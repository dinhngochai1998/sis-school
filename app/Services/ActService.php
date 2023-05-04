<?php
/**
 * @Author Dung
 * @Date   Mar 05, 2022
 */

namespace App\Services;

use App\Helpers\ElasticsearchHelper;
use App\Helpers\RabbitMQHelper;
use Carbon\Carbon;
use Exception;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Exceptions\ForbiddenException;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\ActNoSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class ActService extends BaseService
{
    use RabbitMQHelper, RoleAndPermissionTrait, ElasticsearchHelper;

    private string    $schoolId;
    private UserNoSQL $userNoSql;

    function createModel(): void
    {
        $this->model     = new ActNoSQL();
        $this->userNoSql = new UserNoSQL();
    }

    public function getUserDetailAct(string $userId): object
    {
        $this->schoolId = SchoolServiceProvider::$currentSchool->uuid;
        $query          = $this->queryHelper->buildQuery($this->userNoSql)
                                            ->with(['acts' => function ($query) {
                                                $query->orderBy('test_date', 'DESC');
                                            }])->where('sc_id', $this->schoolId)->where('_id', $userId);

        $response = $query->first();
        (new SbacService())->checkStudentAssigned($response);

        return $response;
    }


    /**
     * @param object $request
     *
     * @return bool
     * @throws Exception
     */
    public function importAct(object $request): bool
    {
        $isGod = $this->isGod();
        $isImportAct
               = $this->hasPermission(PermissionConstant::individualAssessment(PermissionActionConstant::IMPORT));
        if (!$isGod && !$isImportAct) {
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
        $this->pushToExchange($body, 'IMPORT', AMQPExchangeType::DIRECT, 'import_act');
        $log = BaseService::currentUser()->username . ' import act : ' . Carbon::now()->toDateString();
        $this->createELS('import_act',
                         $log,
                         [
                             'file_url' => $body['file_url']
                         ]);

        return true;
    }
}
