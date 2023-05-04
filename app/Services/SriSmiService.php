<?php


namespace App\Services;


use App\Helpers\ElasticsearchHelper;
use App\Helpers\RabbitMQHelper;
use App\Services\impl\MailWithRabbitMQ;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Exceptions\ForbiddenException;
use YaangVu\LaravelBase\Helpers\QueryHelper;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\ScholasticAssessmentNoSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class SriSmiService extends BaseService
{
    use RabbitMQHelper, RoleAndPermissionTrait, ElasticsearchHelper;

    function createModel(): void
    {
        $this->model = new ScholasticAssessmentNoSQL();
    }

    /**
     * @param $userId
     *
     * @return Collection
     */
    public function getGradeSriSmiByUserId($userId): Collection
    {
        $grade = $this->model->whereStudentNosqlId($userId)
                             ->whereSchoolId(SchoolServiceProvider::$currentSchool->id)
                             ->pluck('grade');

        return $grade->unique();
    }

    public function getALlSriSmiAssessment($userId)
    {
        $user     = (new UserService())->get($userId);
        $children = (new FamilyService())->getChildren(BaseService::currentUser()->userNoSql['_id']);

        if (!$this->hasPermission(PermissionConstant::individualAssessment(PermissionActionConstant::VIEW)) && !$children->contains('_id',
                                                                                                                                    $userId) && !$this->isMe($user))
            throw new ForbiddenException(__('role.forbidden'), new Exception());

        $sriSmi = $this->model->whereStudentNosqlId($userId)
                              ->whereSchoolId(SchoolServiceProvider::$currentSchool->id)
                              ->orderBy('grade', 'DESC')
                              ->get();

        if (!$sriSmi) {
            return [];
        }

        $grade     = [];
        $sriDetail = [];
        $smiDetail = [];
        foreach ($sriSmi as $item) {
            $sriDetailGrade      = $item->sri;
            $smiDetailGrade      = $item->smi;
            $grade[]             = $item->grade;
            $countSriDetailGrade = count($sriDetailGrade);
            $countSmiDetailGrade = count($smiDetailGrade);

            // sort sriDetail by date
            for ($i = 0; $i < $countSriDetailGrade - 1; $i++) {
                for ($j = $i + 1; $j < $countSriDetailGrade; $j++) {
                    if (strtotime($sriDetailGrade[$i]['date']) < strtotime($sriDetailGrade[$j]['date'])) {
                        $temp               = $sriDetailGrade[$j];
                        $sriDetailGrade[$j] = $sriDetailGrade[$i];
                        $sriDetailGrade[$i] = $temp;
                    }
                }
            }
            $sriDetail[] = $sriDetailGrade;

            // sort smiDetail by date
            for ($i = 0; $i < $countSmiDetailGrade - 1; $i++) {
                for ($j = $i + 1; $j < $countSmiDetailGrade; $j++) {
                    if (strtotime($smiDetailGrade[$i]['date']) < strtotime($smiDetailGrade[$j]['date'])) {
                        $temp               = $smiDetailGrade[$j];
                        $smiDetailGrade[$j] = $smiDetailGrade[$i];
                        $smiDetailGrade[$i] = $temp;
                    }
                }
            }
            $smiDetail[] = $smiDetailGrade;
        }

        return [
            'user'       => $user,
            'grade'      => $grade,
            'sri_detail' => $sriDetail,
            'smi_detail' => $smiDetail,
        ];
    }

    /**
     * @param $userId
     * @param $gradeId
     *
     * @return array
     */
    public function getSriSmiAssessment($userId, $gradeId): array
    {
        $user     = (new UserService())->get($userId);
        $children = (new FamilyService())->getChildren(BaseService::currentUser()->userNoSql['_id']);

        if (!$this->hasPermission(PermissionConstant::individualAssessment(PermissionActionConstant::VIEW)) && !$children->contains('_id',
                                                                                                                                    $userId) && !$this->isMe($user))
            throw new ForbiddenException(__('role.forbidden'), new Exception());

        $sriSmi = $this->model->whereStudentNosqlId($userId)
                              ->whereGrade($gradeId)
                              ->whereSchoolId(SchoolServiceProvider::$currentSchool->id)
                              ->first();

        if (!$sriSmi) {
            return [];
        }

        return [
            'user'       => $user,
            'grade'      => $sriSmi->grade,
            'smi'        => [
                'smi_percentile'           => $sriSmi->smi_percentile,
                'smi_nce'                  => $sriSmi->smi_nce,
                'smi_stanine'              => $sriSmi->smi_stanine,
                'smi_growth_in_date_range' => $sriSmi->smi_growth_in_date_range,
                'smi_test_taken'           => $sriSmi->smi_test_taken
            ],
            'sri'        => [
                'sri_percentile' => $sriSmi->sri_percentile,
                'sri_nce'        => $sriSmi->sri_nce
            ],
            'sri_detail' => $sriSmi->sri,
            'smi_detail' => $sriSmi->smi
        ];
    }

    /**
     * @return LengthAwarePaginator
     */
    public function getSriSmiReport($request): LengthAwarePaginator
    {
        if (!$this->hasPermission(PermissionConstant::overallAssessment(PermissionActionConstant::VIEW)))
            throw new ForbiddenException(__('role.forbidden'), new Exception());
        $grade   = $request->grade;
        $userId  = $request->_id;
        $userIds = $this->queryHelper->buildQuery(new UserNoSQL())
                                     ->when($grade, function ($q) use ($grade) {
                                         $q->where('grade', $grade);
                                     })
                                     ->when($userId, function ($q) use ($userId) {
                                         $q->where('_id', $userId);
                                     })
                                     ->pluck('_id')->toArray();

        return $this->model::with('user')->whereIn('student_nosql_id', $userIds)
                            ->when($grade, function ($q) use ($grade) {
                                $q->where('grade', (int)$grade);
                            })
                           ->where('school_id',SchoolServiceProvider::$currentSchool->id)
                           ->paginate(QueryHelper::limit());
    }

    /**
     * @param $request
     *
     * @return bool
     * @throws Exception
     */
    public function importSriSmi($request): bool
    {
        if (!$this->hasPermission(PermissionConstant::individualAssessment(PermissionActionConstant::IMPORT)))
            throw new ForbiddenException(__('role.forbidden'), new Exception());

        BaseService::doValidate($request, ['file_url' => 'required']);

        $file_path = explode(env('AMAZON_PATH', 'https://equest-sis.s3.ap-southeast-1.amazonaws.com'),
                             $request->file_url);
        $payload   = [
            'school_id'         => SchoolServiceProvider::$currentSchool->id,
            'school_uuid'       => SchoolServiceProvider::$currentSchool->uuid,
            'imported_by'       => BaseService::currentUser()->id,
            'imported_by_nosql' => BaseService::currentUser()->userNoSql['_id'],
            'file_url'          => $file_path[1],
            'email'             => UserNoSQL::whereUsername(BaseService::currentUser()?->username)
                                            ->first()?->email
        ];

        $this->pushToExchange($payload, 'IMPORT_SRI_SMI', AMQPExchangeType::DIRECT, 'sri_smi');
        $this->createELS('import_sri_smi',
                         self::currentUser()->username . " import SMI SRI at : " . Carbon::now()->toDateString(),
                         [
                             'file_url' => $payload['file_url']
                         ]);

        return true;
    }

    public static function sendMailWhenFalseValidateImport(string $title, array $messages, string $email): string
    {
        $mail  = new MailWithRabbitMQ();
        $error = '';
        foreach ($messages as $message)
            $error = $error . implode('|', $message) . '<br>';
        $mail->sendMails($title, $error, [$email]);

        return $error;
    }

}
