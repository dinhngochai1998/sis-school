<?php

namespace App\Services;

use App\Helpers\RabbitMQHelper;
use Carbon\Carbon;
use DateTimeZone;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;
use MongoDB\BSON\UTCDateTime as MongoDate;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use YaangVu\Constant\NotificationConstant;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Constant\StatusConstant;
use YaangVu\Constant\SurveyConstant;
use YaangVu\Exceptions\ForbiddenException;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Helpers\QueryHelper;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\impl\GradeSQL;
use YaangVu\SisModel\App\Models\impl\JobNoSQL;
use YaangVu\SisModel\App\Models\impl\ProgramSQL;
use YaangVu\SisModel\App\Models\impl\RoleSQL;
use YaangVu\SisModel\App\Models\impl\SurveyAnswerNoSQL;
use YaangVu\SisModel\App\Models\impl\SurveyLogNoSQL;
use YaangVu\SisModel\App\Models\impl\SurveyNoSql;
use YaangVu\SisModel\App\Models\impl\TermSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class SurveyService extends BaseService
{
    use RoleAndPermissionTrait, RabbitMQHelper;

    private UserService $userService;

    function createModel(): void
    {
        $this->model       = new SurveyNoSql();
        $this->userService = new UserService();
    }

    public function preAdd(object $request)
    {

        $isTeacher = $this->hasAnyRole(RoleConstant::TEACHER);
        $isGod     = $this->isGod();
        $dynamic   = $this->hasPermission(PermissionConstant::survey(PermissionActionConstant::ADD));
        if (!$isTeacher && !$isGod && !$dynamic) {
            throw new ForbiddenException(__('role.forbidden'), new Exception());
        }
        $request->merge([
                            'create_by'          => BaseService::currentUser()->userNoSql->_id,
                            'full_name'          => BaseService::currentUser()->userNoSql->full_name,
                            'staff_id'           => BaseService::currentUser()->userNoSql->staff_id,
                            'sent_date'          => null,
                            'role_name'          => !$isGod ? RoleConstant::TEACHER : null,
                            'sc_id'              => SchoolServiceProvider::$currentSchool->uuid,
                            'user_respondent_id' => [],
                        ]);
        $ruleOtherStaffs = $ruleStudent = $ruleTeacher = $ruleFamily = [];
        $respondent      = $request->gerneral_information['respondent_setting'];
        $questionType    = implode(',', SurveyConstant::QUESTION_TYPE);
        $statusSurvey    = implode(',', [
            StatusConstant::ACTIVATED,
            StatusConstant::PENDING,
            StatusConstant::ARCHIVED,
        ]);
        $now             = Carbon::now()->format('Y-m-d');
        $rules           = [
            'gerneral_information.title'                     => 'required',
            'gerneral_information.respondent_setting'        => 'required',
            'status'                                         => 'in:' . $statusSurvey,
            'gerneral_information.effective_end_date'        => 'date|date_format:Y-m-d|after_or_equal:' . $now,
            'gerneral_information.appearance_report_setting' => 'boolean',
            'survey_structure.questions.*.type'              => 'in:' . $questionType,
            'survey_structure.questions.*.number_only'       => 'boolean'
        ];
        if (!empty($respondent[SurveyConstant::OTHER_STAFFS])) {
            $ruleOtherStaffs = [
                'gerneral_information.respondent_setting.other_staffs.filter_id.*' => 'exists:roles,id',
            ];
        }

        if (!empty($respondent[SurveyConstant::FAMILY])) {

            $ruleFamily
                = $this->getRulesByFilterSurvey($respondent[SurveyConstant::FAMILY], SurveyConstant::FAMILY);
        }
        if (!empty($respondent[SurveyConstant::TEACHER])) {
            $ruleTeacher
                = $this->getRulesByFilterSurvey($respondent[SurveyConstant::TEACHER], SurveyConstant::TEACHER);
        }
        if (!empty($respondent[SurveyConstant::STUDENT])) {
            $ruleStudent
                = $this->getRulesByFilterSurvey($respondent[SurveyConstant::STUDENT], SurveyConstant::STUDENT);
        }
        $rulesSurvey = array_merge($rules, $ruleFamily, $ruleTeacher, $ruleStudent, $ruleOtherStaffs);

        BaseService::doValidate($request, $rulesSurvey);
        parent::preAdd($request);
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
    public function getRulesByFilterSurvey(array $respondent, $object): array
    {
        switch ($respondent['filter']) {
            case $respondent['filter'] == SurveyConstant::CLASSES || $respondent['filter'] == SurveyConstant::ALL:
                $ruleFilter = [
                    'gerneral_information.respondent_setting.' . $object . '.filter_id.*' => 'exists:classes,id',
                    'gerneral_information.respondent_setting.' . $object . '.status'      => 'boolean'
                ];
                break;
            case $respondent['filter'] == SurveyConstant::TERM || $respondent['filter'] == SurveyConstant::ALL:
                $ruleFilter = [
                    'gerneral_information.respondent_setting.' . $object . '.filter_id.*' => 'exists:terms,id',
                    'gerneral_information.respondent_setting.' . $object . '.status'      => 'boolean'
                ];
                break;
            case $respondent['filter'] == SurveyConstant::PROGRAM || $respondent['filter'] == SurveyConstant::ALL:
                $ruleFilter = [
                    'gerneral_information.respondent_setting.' . $object . '.filter_id.*' => 'exists:programs,id',
                    'gerneral_information.respondent_setting.' . $object . '.status'      => 'boolean'
                ];
                break;
            case $respondent['filter'] == SurveyConstant::GRADE || $respondent['filter'] == SurveyConstant::ALL:
                $ruleFilter = [
                    'gerneral_information.respondent_setting.' . $object . '.filter_id.*' => 'exists:grades,name',
                    'gerneral_information.respondent_setting.' . $object . '.status'      => 'boolean'
                ];
                break;
            case $respondent['filter'] == SurveyConstant::TEACHER || $respondent['filter'] == SurveyConstant::STUDENT || $respondent['filter'] == SurveyConstant::FAMILY:
            case $respondent['filter'] == SurveyConstant::FAMILY || $respondent['filter'] == SurveyConstant::ALL:
                $ruleFilter = [
                    'gerneral_information.respondent_setting.' . $object . '.filter_id.*' => 'exists:mongodb.users,_id',
                ];
                break;
            default:
                return [];
        }

        return $ruleFilter;
    }

    /**
     * @throws Exception
     */
    public function getAll(): LengthAwarePaginator
    {
        $isTeacher      = $this->hasAnyRole(RoleConstant::TEACHER);
        $dynamicOrIsGod = $this->hasPermission(PermissionConstant::survey(PermissionActionConstant::LIST));

        if (!$dynamicOrIsGod && !$isTeacher) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }

        $this->preGetAll();
        $request   = Request();
        $title     = $request['title'] ?? null;
        $startDate = isset($request['sent_date'][0]) ? $request['sent_date'][0] : null;
        $endDate   = isset($request['sent_date'][1]) ? $request['sent_date'][1] : null;
        $this->queryHelper->removeParam('title');
        $this->queryHelper->removeParam('sent_date');
        $data = $this->queryHelper
            ->buildQuery($this->model)
            ->when($title, function ($q) use ($title) {
                $q->where('gerneral_information.title', 'like', '%' . $title . '%');
            })
            ->when($startDate, function ($q) use ($startDate) {
                $q->whereDate('sent_date', '>=',
                              $this->convertMongoDate(Carbon::parse($startDate)->startOfDay()->format('Uv')));
            })
            ->when($endDate, function ($q) use ($endDate) {
                $q->whereDate('sent_date', '<=',
                              $this->convertMongoDate(Carbon::parse($endDate)->endOfDay()->format('Uv')));
            })
            ->where('sc_id', SchoolServiceProvider::$currentSchool->uuid);

        try {
            if (!$dynamicOrIsGod) {
                $user = BaseService::currentUser()->userNoSql->_id;
                $data->where('create_by', $user)
                     ->where('role_name', RoleConstant::TEACHER);
            }
            $arrCreatedBy     = $data->select('*')->pluck('create_by')->toArray();
            $arrIdSurveys     = $data->select('*')->pluck('_id')->toArray();
            $users            = UserNoSQL::query()->whereIn('_id', $arrCreatedBy)->get();
            $response         = $data->orderByDesc('sent_date')->paginate(QueryHelper::limit());
            $respondedSurveys = SurveyAnswerNoSQL::query()->whereIn('survey_id', $arrIdSurveys)->get();
            $response->getCollection()->transform(function ($object) use ($users, $respondedSurveys) {
                $object->sent_date = $object->sent_date ? $object->sent_date->toDateTime()
                                                                            ->format('Y-m-d H:i:s') : null;
                $object->full_name = $users->where('_id', $object->create_by)->first()->full_name;
                $object->responded = $respondedSurveys->where('survey_id', $object->id)->unique('user_id')->count();

                return $object;
            });
            $this->postGetAll($response);

            return $response;
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    public function convertMongoDate(string $date): MongoDate
    {
        return new MongoDate($date);
    }

    /**
     * @throws Exception
     */
    public function postAdd(object $request, Model $model)
    {
        $respondents                = (array)$model->gerneral_information['respondent_setting'];
        $allEmailAndFullNameSurveys = $this->getEmailAndFullNameRespondentSetting($respondents) ?? [];
        $respondentIds              = array_column($allEmailAndFullNameSurveys, '_id');
        $model->user_respondent_id  = array_unique($respondentIds);
        $model->save();
        if ($request->status == StatusConstant::PENDING) {
            return $model;
        }
        parent::postAdd($request, $model); // TODO: Change the autogenerated stub
        if ($request->status == StatusConstant::ACTIVATED) {
            $sendAt           = new UTCDateTime(Carbon::now());
            $model->sent_date = $sendAt;
            $model->save();
            $body = [
                'users'        => $allEmailAndFullNameSurveys,
                'school_uuid'  => SchoolServiceProvider::$currentSchool->uuid,
                'survey_url'   => env('URL_SURVEY'),
                'survey'       => $model,
                'current_user' => BaseService::currentUser()?->userNoSql
            ];
            $this->saveJobSendSurvey($request, $model, $allEmailAndFullNameSurveys, $sendAt);
            $this->pushToExchange($body, 'SEND_NOTIFICATION', AMQPExchangeType::DIRECT, 'notification_survey');
        }
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jun 01, 2022
     *
     * @param array $respondents
     *
     * @return array
     */
    public function getEmailAndFullNameRespondentSetting(array $respondents): array
    {
        $emailFamily = $emailStudent = $emailTeachers = $emailByRoleId = [];

        if (!empty($respondents[SurveyConstant::FAMILY])) {
            $emailFamily = $this->getEmailAndFullNameByFilterSurvey($respondents[SurveyConstant::FAMILY],
                                                                    SurveyConstant::FAMILY);
            if ($respondents[SurveyConstant::FAMILY]['filter'] == SurveyConstant::ALL) {
                $emailFamily
                    = $this->userService->getUserUuidsViaRoleName($this->decorateWithSchoolUuid(RoleConstant::FAMILY),
                                                                  SurveyConstant::FAMILY);
            }

        }
        if (!empty($respondents[SurveyConstant::TEACHER])) {
            $emailTeachers = $this->getEmailAndFullNameByFilterSurvey($respondents[SurveyConstant::TEACHER],
                                                                      SurveyConstant::TEACHER);
            if ($respondents[SurveyConstant::TEACHER]['filter'] == SurveyConstant::ALL) {
                $emailTeachers
                    = $this->userService->getUserUuidsViaRoleName($this->decorateWithSchoolUuid(RoleConstant::TEACHER));
            }
        }
        if (!empty($respondents[SurveyConstant::STUDENT])) {
            $emailStudent = $this->getEmailAndFullNameByFilterSurvey($respondents[SurveyConstant::STUDENT],
                                                                     SurveyConstant::STUDENT);
            if ($respondents[SurveyConstant::STUDENT]['filter'] == SurveyConstant::ALL) {
                $emailStudent
                    = $this->userService->getUserUuidsViaRoleName($this->decorateWithSchoolUuid(RoleConstant::STUDENT));
            }
        }
        if (!empty($respondents[SurveyConstant::OTHER_STAFFS])) {
            $emailByRoleId
                = $this->userService->getUserByRoleId($respondents[SurveyConstant::OTHER_STAFFS]['filter_id'],
                                                      $respondents[SurveyConstant::OTHER_STAFFS]['status']);
        }

        return array_merge($emailFamily, $emailTeachers, $emailStudent, $emailByRoleId);
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
     * @return Collection|array
     */
    public function getEmailAndFullNameByFilterSurvey(array $respondent, $object): Collection|array
    {
        switch ($respondent['filter']) {
            case $respondent['filter'] == SurveyConstant::CLASSES:
                $emails = $this->userService->getEmailAndFullNameUserByClassId($respondent['filter_id'], $object,
                                                                               $respondent['status']);
                break;
            case $respondent['filter'] == SurveyConstant::TERM:
                $emails = $this->userService->getEmailAndFullNameUserByTermId($respondent['filter_id'],
                                                                              $object,
                                                                              $respondent['status']);
                break;
            case $respondent['filter'] == SurveyConstant::PROGRAM:
                $emails = $this->userService->getEmailAndFullNameUserByProgramId($respondent['filter_id'],
                                                                                 $object,
                                                                                 $respondent['status']);
                break;
            case $respondent['filter'] == SurveyConstant::GRADE:
                $emails = $this->userService->getEmailAndFullNameStudentFamilyByGrade($respondent['filter_id'],
                                                                                      $object,
                                                                                      $respondent['status']);
                break;
            case $respondent['filter'] == SurveyConstant::TEACHER || $respondent['filter'] == SurveyConstant::STUDENT || $respondent['filter'] == SurveyConstant::FAMILY:
                $emails = $this->userService->getAllStudentByUserIds($respondent['filter_id'], $object);
                break;
            default:
                return [];
        }

        return $emails;
    }

    /**
     * @throws Exception
     */
    public function saveJobSendSurvey(object $request, Model $model, array $userNoSqls, MongoDate $sendAt): void
    {
        $jobSendMailSurvey = [];
        $surveyLogHash     = [];
        $bodyEmail         = $model->email_content['body'];
        $arrReplace        = ['${full_name}', '${survey_url}'];
        $templateLink      = env('URL_SURVEY');
        foreach ($userNoSqls as $userNoSql) {
            $hash                = $this->generateHashCode();
            $receiverUuids       = [];
            $receiverUuids[]     = !empty($userNoSql['uuid']) ? $userNoSql['uuid'] : null;
            $jobSendMailSurvey[] = [
                'send_type'      => NotificationConstant::NOTIFICATION_EMAIL,
                'receiver_uuids' => $receiverUuids,
                'title'          => $model->email_content['subject'],
                'body'           => str_replace($arrReplace,
                                                [$userNoSql['full_name'], $templateLink . $model->_id . '/' . $hash],
                                                $bodyEmail),
                'status'         => StatusConstant::PENDING,
                'send_at'        => $sendAt,
                'html_enabled'   => true,
            ];

            $surveyLogHash[] = [
                'survey_id'      => $model->_id,
                'user_id'        => $userNoSql['_id'],
                'sc_id'          => $model->sc_id,
                'effective_date' => new MongoDate(Carbon::parse($model->gerneral_information['effective_end_date'])),
                'hash_code'      => $hash,
            ];
        }

        if (!empty($userNoSqls)) {
            JobNoSQL::query()->insert($jobSendMailSurvey);
            SurveyLogNoSQL::query()->insert($surveyLogHash);
        }

    }

    /**
     * @throws Exception
     */
    public function generateHashCode(): string
    {
        $hash  = Str::random(80);
        $check = strpos($hash, '/') ? $this->generateHashCode() : true;

        return $hash;

    }

    /**
     * @throws Exception
     */
    public function postUpdate(int|string $id, object $request, Model $model)
    {
        $userIdNoSql = $model->user_respondent_id;
        $userNoSqls  = UserNoSQL::query()->whereIn('_id', $userIdNoSql)->get()->toArray();
        $model->save();
        parent::postUpdate($id, $request, $model); // TODO: Change the autogenerated stub
        if ($request->status == StatusConstant::ACTIVATED) {
            $sendAt           = $this->convertMongoDate(Date::now()->format('Uv'));
            $model->sent_date = $sendAt;
            $model->save();
            $body = [
                'users'        => $userNoSqls,
                'school_uuid'  => SchoolServiceProvider::$currentSchool->uuid,
                'survey_url'   => env('URL_SURVEY'),
                'survey'       => $model,
                'current_user' => BaseService::currentUser()?->userNoSql
            ];
            $this->saveJobSendSurvey($request, $model, $userNoSqls, $sendAt);
            $this->pushToExchange($body, 'SEND_NOTIFICATION', AMQPExchangeType::DIRECT, 'notification_survey');
        }
    }

    /**
     * @throws Exception
     */
    public function getDetailSurvey(string $surveyId, string $hash): Model|array
    {
        $dataRequest = (object)[
            'survey_id' => $surveyId,
            'hash'      => $hash,
        ];
        $rules       = [
            'survey_id' => 'required|exists:mongodb.surveys,_id',
            'hash'      => 'required|exists:mongodb.survey_logs,hash_code'
        ];
        BaseService::doValidate($dataRequest, $rules);

        $survey       = SurveyNoSql::query()->where('_id', $surveyId)->first();
        $surveyLog    = SurveyLogNoSQL::query()->where('hash_code', $hash)->first();
        $surveyAnswer = SurveyAnswerNoSQL::query()
                                         ->where('user_id', $surveyLog->user_id)
                                         ->where('survey_id', $surveyLog->survey_id)
                                         ->first();

        $date
            = $survey->gerneral_information['effective_end_date'] ? Carbon::parse($survey->gerneral_information['effective_end_date'])
                                                                          ->format('Y-m-d') : null;
        if (($date < Carbon::now()->format('Y-m-d')) && !empty($date) || !empty($surveyAnswer)) {
            return [];
        }

        return SurveyNoSql::query()->where('_id', $surveyId)->first();
    }

    public function postGet(int|string $id, Model $model)
    {
        $family            = $teachers = $students = $otherStaffs = [];
        $respondentSetting = $model->gerneral_information['respondent_setting'];

        if (!empty($respondentSetting[SurveyConstant::FAMILY])) {
            $filterIds        = $respondentSetting[SurveyConstant::FAMILY]['filter_id'];
            $family['family'] = $this->getFilterByFilterIds($respondentSetting[SurveyConstant::FAMILY], $filterIds);
        }
        if (!empty($respondentSetting[SurveyConstant::TEACHER])) {
            $filterIds           = $respondentSetting[SurveyConstant::TEACHER]['filter_id'];
            $teachers['teacher'] = $this->getFilterByFilterIds($respondentSetting[SurveyConstant::TEACHER], $filterIds);
        }
        if (!empty($respondentSetting[SurveyConstant::STUDENT])) {
            $filterIds           = $respondentSetting[SurveyConstant::STUDENT]['filter_id'];
            $students['student'] = $this->getFilterByFilterIds($respondentSetting[SurveyConstant::STUDENT], $filterIds);
        }
        if (!empty($respondentSetting[SurveyConstant::OTHER_STAFFS])) {
            $filterIds                   = $respondentSetting[SurveyConstant::OTHER_STAFFS]['filter_id'];
            $otherStaffs['other_staffs'] = RoleSQL::query()->whereIn('id', $filterIds)->get();
        }
        $model->{'list_edit'} = array_merge($family, $teachers, $students, $otherStaffs);
        parent::postGet($id, $model); // TODO: Change the autogenerated stub
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jun 01, 2022
     *
     * @param array $respondent
     * @param       $filterIds
     *
     * @return Collection|array
     */
    public function getFilterByFilterIds(array $respondent, $filterIds): Collection|array
    {
        switch ($respondent['filter']) {
            case $respondent['filter'] == SurveyConstant::CLASSES :
                $data = ClassSQL::query()->whereIn('id', $filterIds)->get();
                break;
            case $respondent['filter'] == SurveyConstant::TERM:
                $data = TermSQL::query()->whereIn('id', $filterIds)->get();
                break;
            case $respondent['filter'] == SurveyConstant::PROGRAM:
                $data = ProgramSQL::query()->whereIn('id', $filterIds)->get();
                break;
            case $respondent['filter'] == SurveyConstant::GRADE:
                $data = GradeSQL::query()->whereIn('name', $filterIds)->get();
                break;
            case $respondent['filter'] == SurveyConstant::TEACHER || $respondent['filter'] == SurveyConstant::STUDENT || $respondent['filter'] == SurveyConstant::FAMILY:
                $data = UserNoSQL::query()->whereIn('_id', $filterIds)->get()->toArray();
                break;
            case $respondent['filter'] == SurveyConstant::OTHER_STAFFS:
                $data = RoleSQL::query()->where('id', $filterIds);
                break;
            default:
                return [];
        }

        return $data;
    }
}
