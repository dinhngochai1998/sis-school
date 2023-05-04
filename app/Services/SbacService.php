<?php
/**
 * @Author Dung
 * @Date   Mar 09, 2022
 */

namespace App\Services;

use App\Helpers\ElasticsearchHelper;
use App\Helpers\RabbitMQHelper;
use Carbon\Carbon;
use Exception;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\ForbiddenException;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\ActNoSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class SbacService extends BaseService
{
    use RabbitMQHelper, RoleAndPermissionTrait, ElasticsearchHelper;

    private UserNoSQL $userNoSQL;
    private string    $schoolId;

    function createModel(): void
    {
        $this->userNoSQL = new UserNoSQL();
        $this->model     = new ActNoSQL();
    }

    public function getUserDetailSbac(string|int $userId): object
    {
        $this->schoolId = SchoolServiceProvider::$currentSchool->uuid;
        $query          = $this->queryHelper->buildQuery($this->userNoSQL)
                                            ->with(['sbacs' => function ($query) {
                                                $query->orderBy('grade', 'DESC');
                                            }])
                                            ->where('sc_id', $this->schoolId)
                                            ->where('_id', $userId);

        $userNoSQL = $query->first();
        $this->checkStudentAssigned($userNoSQL);

        return $userNoSQL;
    }

    public function checkStudentAssigned($response): void
    {
        $isGod                = $this->isGod();
        $isTeacherOrCounselor = $this->hasAnyRole(RoleConstant::TEACHER, RoleConstant::COUNSELOR);
        $isStudent            = $this->hasAnyRole(RoleConstant::STUDENT);
        $isViewReport
                              = $this->hasPermission(PermissionConstant::individualAssessment(PermissionActionConstant::VIEW));

        if (!$isGod && !$isTeacherOrCounselor && !$isViewReport && !$isStudent) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }
        if (empty($response->uuid)) {
            throw new BadRequestException(__('assignedStudentError.student_not_found'), new Exception());
        }
        $studentUuid        = $response->uuid;
        $assignedStudent    = BaseService::currentUser()->userNoSql->assigned_student_uuids;
        $currentStudentUuid = BaseService::currentUser()->userNoSql->uuid;
        if ($isTeacherOrCounselor && !$isGod) {
            if (($assignedStudent && !in_array($studentUuid, $assignedStudent)) || !$assignedStudent)
                throw new BadRequestException(__('assignedStudentError.assigned'), new Exception());
        }
        if ($isStudent && !$isGod && !$isTeacherOrCounselor) {
            if ($currentStudentUuid != $studentUuid)
                throw new BadRequestException(__('assignedStudentError.assigned'), new Exception());
        }
    }

    /**
     * @throws Exception
     */
    public function importSbac(object $request): bool
    {
        $isPrincipal = $this->hasAnyRole(RoleConstant::PRINCIPAL);
        $isAdmin     = $this->hasAnyRole(RoleConstant::ADMIN);

        $isImportSbac
            = $this->hasPermission(PermissionConstant::individualAssessment(PermissionActionConstant::IMPORT));
        if (!$isPrincipal && !$isAdmin && !$isImportSbac) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }
        $this->doValidate($request, [
            'file_url' => 'required',
        ]);
        $fileUrl = $request->file_url;
        // 4 = ".com" length
        $filePath  = substr($fileUrl, strpos($fileUrl, ".com") + 4);
        $filePathActionLog = explode(env('AMAZON_PATH', 'https://equest-sis.s3.ap-southeast-1.amazonaws.com'),
                             $fileUrl);
        $body      = [
            'url'         => $fileUrl,
            'file_path'   => $filePath,
            'file_url'    => $filePathActionLog[1],
            'school_uuid' => SchoolServiceProvider::$currentSchool->uuid,
            'email'       => BaseService::currentUser()->userNoSql->email,
        ];

        $this->pushToExchange($body, 'IMPORT', AMQPExchangeType::DIRECT, 'import_sbac');
        $log = BaseService::currentUser()->username . ' import sbac : ' . Carbon::now()->toDateString();
        $this->createELS('import_sbac',
                         $log,
                         [
                             'file_url' => $body['file_url']
                         ]);

        return true;
    }
}
