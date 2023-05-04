<?php
/**
 * @Author Admin
 * @Date   Jul 11, 2022
 */

namespace App\Services;

use App\Helpers\RabbitMQHelper;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Constant\StatusSmsTemplateConstant;
use YaangVu\Constant\SurveyConstant;
use YaangVu\Exceptions\ForbiddenException;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Helpers\QueryHelper;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\SmsParticipantSQL;
use YaangVu\SisModel\App\Models\impl\SmsSettingSQL;
use YaangVu\SisModel\App\Models\impl\SmsSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class SmsParticipantService extends BaseService
{
    use RabbitMQHelper, RoleAndPermissionTrait;

    private UserService $userService;

    public function __construct()
    {
        $this->userService = new UserService();
        parent::__construct();
    }

    function createModel(): void
    {
        $this->model = new SmsParticipantSQL();

    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jul 19, 2022
     *
     * @param $id
     *
     * @return array
     */
    public function reportSms($id): array
    {
        $isStaff = $this->hasAnyRole(RoleConstant::STAFF);
        if (!$isStaff && !$this->hasPermission(PermissionConstant::communication(PermissionActionConstant::LIST_SMS))) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }
        $sms   = SmsSQL::query()->with(['smsParticipants'])->where('id', $id)->first();
        $query = $this->queryHelper->buildQuery($this->model)->where('sms_id', $sms->id);
        try {
            $arrUuids              = $query->pluck('user_uuid')->toArray();
            $countUsers            = $sms->count_user ?? 0;
            $smsParticipants       = $query->paginate(QueryHelper::limit());
            $delivered             = $query->where('status', StatusSmsTemplateConstant::DELIVERED)->get();
            $sent                  = count($delivered) . '/' . $countUsers;
            $users                 = UserNoSQL::query()->whereIn('uuid', $arrUuids)->get();
            $response              = $smsParticipants->getCollection()->transform(function ($object) use ($users) {
                $user                  = $users->where('uuid', $object->user_uuid)->first();
                $object->receiver_info = $user->full_name ?? null;
                $object->role_names    = $user->role_names ?? null;

                return $object;
            });
            $paginateListReportSms = (new SurveyReportService())->paginateCollection($smsParticipants, $response);

            return [
                'title'             => $sms->title ?? null,
                'updated_date_time' => $sms->updated_at,
                'sent_date_time'    => $sms->created_at,
                'sent'              => $sent,
                'content'           => $sms->content ?? null,
                'messaging_logs'    => $paginateListReportSms,
            ];
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @throws Exception
     */
    public function preAdd(object $request)
    {
        $isStaff = $this->hasAnyRole(RoleConstant::STAFF);
        if (!$isStaff && !$this->hasPermission(PermissionConstant::communication(PermissionActionConstant::SEND_SMS))) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }
        $rules          = [
            'content'          => 'required',
            'choose_recipient' => 'required',
            'provider_id'      => 'required|integer|exists:sms_settings,id',
            'title'            => 'max:255'
        ];
        $ruleOtherStaff = $ruleStudent = $ruleTeacher = $ruleFamily = [];
        $recipientSms   = $request->choose_recipient;
        if (!empty($recipientSms[SurveyConstant::OTHER_STAFFS])) {
            $ruleOtherStaff = [
                'choose_recipient.filter_id.*' => 'exists:roles,id',
            ];
        }
        if (!empty($recipientSms[SurveyConstant::FAMILY])) {
            $ruleFamily
                = $this->getRulesByFilterSms($recipientSms[SurveyConstant::FAMILY], SurveyConstant::FAMILY);
        }
        if (!empty($recipientSms[SurveyConstant::TEACHER])) {
            $ruleTeacher
                = $this->getRulesByFilterSms($recipientSms[SurveyConstant::TEACHER], SurveyConstant::TEACHER);
        }
        if (!empty($recipientSms[SurveyConstant::STUDENT])) {
            $ruleStudent
                = $this->getRulesByFilterSms($recipientSms[SurveyConstant::STUDENT], SurveyConstant::STUDENT);
        }
        $rulesSms = array_merge($rules, $ruleFamily, $ruleTeacher, $ruleStudent, $ruleOtherStaff);

        BaseService::doValidate($request, $rulesSms);
        $chooseRecipient = $this->getChooseRecipient($request->choose_recipient);

        $dataSms = [
            'template_id' => $request->template_id ?? null,
            'count_user'  => count($chooseRecipient),
            'title'       => $request->title ?? null,
            'content'     => $request->content ?? null,
            'created_by'  => BaseService::currentUser()->id,
            'school_id'   => SchoolServiceProvider::$currentSchool->id ?? null,
        ];

        $sms  = SmsSQL::query()->create($dataSms);
        $body = [
            'providerId'      => $request->provider_id,
            'templateId'      => $request->template_id,
            'title'           => $request->title,
            'content'         => $request->content,
            'chooseRecipient' => $chooseRecipient,
            'currentUserId'   => BaseService::currentUser()->id,
            'schoolId'        => SchoolServiceProvider::$currentSchool->id,
            'smsId'           => $sms->id
        ];

        $this->pushToExchange($body, 'SMS', AMQPExchangeType::DIRECT, 'send_sms');
        parent::preAdd($request); // TODO: Change the autogenerated stub
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jun 01, 2022
     *
     * @param array $respondent
     * @param       $object
     *
     * @return array
     */
    public function getRulesByFilterSms(array $respondent, $object): array
    {
        switch ($respondent['filter']) {
            case $respondent['filter'] == SurveyConstant::CLASSES || $respondent['filter'] == SurveyConstant::ALL:
                $ruleFilter = [
                    'choose_recipient.' . $object . '.filter_id.*' => 'exists:classes,id',
                    'choose_recipient' . $object . '.status'       => 'boolean'
                ];
                break;
            case $respondent['filter'] == SurveyConstant::TERM || $respondent['filter'] == SurveyConstant::ALL:
                $ruleFilter = [
                    'choose_recipient.' . $object . '.filter_id.*' => 'exists:terms,id',
                    'choose_recipient.' . $object . '.status'      => 'boolean'
                ];
                break;
            case $respondent['filter'] == SurveyConstant::PROGRAM || $respondent['filter'] == SurveyConstant::ALL:
                $ruleFilter = [
                    'choose_recipient.' . $object . '.filter_id.*' => 'exists:programs,id',
                    'choose_recipient.' . $object . '.status'      => 'boolean'
                ];
                break;
            case $respondent['filter'] == SurveyConstant::GRADE || $respondent['filter'] == SurveyConstant::ALL:
                $ruleFilter = [
                    'choose_recipient.' . $object . '.filter_id.*' => 'exists:grades,name',
                    'choose_recipient.' . $object . '.status'      => 'boolean'
                ];
                break;
            case $respondent['filter'] == SurveyConstant::USER || $respondent['filter'] == SurveyConstant::ALL:
            case $respondent['filter'] == SurveyConstant::FAMILY || $respondent['filter'] == SurveyConstant::ALL:
                $ruleFilter = [
                    'choose_recipient.' . $object . '.filter_id.*' => 'exists:mongodb.users,_id',
                    'choose_recipient' . $object . '.status'       => 'boolean'
                ];
                break;
            default:
                return [];
        }

        return $ruleFilter;
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jun 01, 2022
     *
     * @param array $chooseRecipient
     *
     * @return array
     */
    public function getChooseRecipient(array $chooseRecipient): array
    {
        $family = $teachers = $students = $staffs = [];
        if (!empty($chooseRecipient[SurveyConstant::FAMILY])) {
            $family = $this->getUserByFilterSms($chooseRecipient[SurveyConstant::FAMILY],
                                                SurveyConstant::FAMILY);
            if ($chooseRecipient[SurveyConstant::FAMILY]['filter'] == SurveyConstant::ALL) {
                $family
                    = $this->userService->getUserUuidsViaRoleName($this->decorateWithSchoolUuid(RoleConstant::STUDENT),
                                                                  SurveyConstant::FAMILY);
            }
        }
        if (!empty($chooseRecipient[SurveyConstant::TEACHER])) {
            $teachers = $this->getUserByFilterSms($chooseRecipient[SurveyConstant::TEACHER],
                                                  SurveyConstant::TEACHER);
            if ($chooseRecipient[SurveyConstant::TEACHER]['filter'] == SurveyConstant::ALL) {
                $teachers
                    = $this->userService->getUserUuidsViaRoleName($this->decorateWithSchoolUuid(RoleConstant::TEACHER));
            }
        }
        if (!empty($chooseRecipient[SurveyConstant::STUDENT])) {
            $students = $this->getUserByFilterSms($chooseRecipient[SurveyConstant::STUDENT],
                                                  SurveyConstant::STUDENT);

            if ($chooseRecipient[SurveyConstant::STUDENT]['filter'] == SurveyConstant::ALL) {
                $students
                    = $this->userService->getUserUuidsViaRoleName($this->decorateWithSchoolUuid(RoleConstant::STUDENT));
            }
        }
        if (!empty($chooseRecipient[SurveyConstant::OTHER_STAFFS])) {
            $staffs
                = $this->userService->getUserByRoleId($chooseRecipient[SurveyConstant::OTHER_STAFFS]['filter_id'],
                                                      $chooseRecipient[SurveyConstant::OTHER_STAFFS]['status']);
        }

        return array_merge($family, $teachers, $students, $staffs);

    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jun 01, 2022
     *
     * @param array $chooseRecipient
     * @param       $object
     *
     * @return Collection|array
     */
    public function getUserByFilterSms(array $chooseRecipient, $object): Collection|array
    {
        switch ($chooseRecipient['filter']) {
            case $chooseRecipient['filter'] == SurveyConstant::CLASSES:
                $users = $this->userService->getEmailAndFullNameUserByClassId($chooseRecipient['filter_id'], $object,
                                                                              $chooseRecipient['status']);
                break;
            case $chooseRecipient['filter'] == SurveyConstant::TERM:
                $users = $this->userService->getEmailAndFullNameUserByTermId($chooseRecipient['filter_id'],
                                                                             $object,
                                                                             $chooseRecipient['status']);
                break;
            case $chooseRecipient['filter'] == SurveyConstant::PROGRAM:
                $users = $this->userService->getEmailAndFullNameUserByProgramId($chooseRecipient['filter_id'],
                                                                                $object,
                                                                                $chooseRecipient['status']);
                break;
            case $chooseRecipient['filter'] == SurveyConstant::GRADE:
                $users = $this->userService->getEmailAndFullNameStudentFamilyByGrade($chooseRecipient['filter_id'],
                                                                                     $object,
                                                                                     $chooseRecipient['status']);
                break;
            case $chooseRecipient['filter'] == SurveyConstant::USER:
                $users = $this->userService->getAllStudentByUserIds($chooseRecipient['filter_id'], $object);
                break;
            case $chooseRecipient['filter'] == SurveyConstant::FAMILY:
                $users = $this->userService->getUserById($chooseRecipient['filter_id']);
                break;
            default:
                return [];
        }

        return $users;
    }

    public function hookStatusSms(object $request): bool
    {
        $request = $request->toArray();

        $this->model
            ->where('id', $request['id'] ?? null)
            ->where('hash_code', $request['hash'] ?? null)
            ->update([
                         'status'         => $request['SmsStatus'] ?? null,
                         'external_id'    => $request['SmsSid'] ?? null,
                         'sent_date_time' => Carbon::now()->format('Y-m-d H:i:s')
                     ]);

        Log::info('data', ["Update status sms participant success!", $request]);

        return true;
    }

}
