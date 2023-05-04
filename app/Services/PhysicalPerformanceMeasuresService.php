<?php
/**
 * @Author Admin
 * @Date   Mar 03, 2022
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
use YaangVu\SisModel\App\Models\impl\PhysicalPerformanceMeasuresNoSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class PhysicalPerformanceMeasuresService extends BaseService
{
    use RabbitMQHelper, RoleAndPermissionTrait, ElasticsearchHelper;

    private ReportService $reportService;

    function createModel(): void
    {
        $this->reportService = new ReportService();
        $this->model         = new PhysicalPerformanceMeasuresNoSQL();
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Mar 30, 2022
     *
     * @param $request
     *
     * @return bool|array
     * @throws Exception
     */
    public function importPhysicalPerformanceMeasures($request): bool|array
    {
        if (!$this->hasPermission(PermissionConstant::individualAssessment(PermissionActionConstant::IMPORT))) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }
        $this->doValidate($request, [
            'file_url' => 'required',
        ]);

        $fileUrl   = $request->file_url;
        $filePath  = substr($fileUrl, strpos($fileUrl, ".com") + 4);
        $filePathActionLog = explode(env('AMAZON_PATH', 'https://equest-sis.s3.ap-southeast-1.amazonaws.com'),
                             $fileUrl);
        $body      = [
            'url'         => $fileUrl,
            'file_path'   => $filePath,
            'file_url'    => $filePathActionLog[1],
            'school_uuid' => SchoolServiceProvider::$currentSchool->uuid,
            'email'       => BaseService::currentUser()->userNoSql->email
        ];
        $this->pushToExchange($body, 'IMPORT', AMQPExchangeType::DIRECT, 'physical_performance');
        $log = BaseService::currentUser()->username . ' import physical performance measures : ' . Carbon::now()->toDateString();
        $this->createELS('import_physical_performance_measures',
                         $log,
                         [
                             'file_url' => $body['file_url']
                         ]);

        return true;
    }

}