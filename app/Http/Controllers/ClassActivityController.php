<?php

namespace App\Http\Controllers;

use App\Exports\ClassActivityExport;
use App\Exports\ClassActivityTemplateExport;
use App\Services\ClassActivityService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\LaravelBase\Controllers\BaseController;
use YaangVu\SisModel\App\Models\impl\ClassActivityNoSql;
use YaangVu\SisModel\App\Models\impl\ClassSQL;

class ClassActivityController extends BaseController
{
    public function __construct()
    {
        $this->service = new ClassActivityService();
        parent::__construct();
    }

    /**
     * @param Request $request
     * @param int     $id
     *
     * @return JsonResponse
     * @throws Exception
     */
    public function importActivityScore(Request $request, int $id): JsonResponse
    {
        return response()->json($this->service->importActivityScore($request, $id));
    }

    public function exportLmsClassActivity(int $id): BinaryFileResponse
    {
        $lmsName = ClassSQL::query()->join('lms','lms.id','=','classes.lms_id')
                           ->where('classes.id',$id)
                           ->select('lms.name')->first();

        $amountClassActivity = ClassActivityNoSql::whereClassId($id)->count();

        if (empty($lmsName) || $lmsName->name == LmsSystemConstant::SIS || $amountClassActivity == 0) {
            throw new BadRequestException(
                ['message' => __("classActivity.export_score")], new Exception()
            );
        }

        return (new ClassActivityExport($id))->download();
    }

    public function exportSisClassActivity(int $classId): BinaryFileResponse
    {
        $lmsName = ClassSQL::query()->join('lms','lms.id','=','classes.lms_id')
                           ->where('classes.id',$classId)
                           ->select('lms.name')->first();

        $amountClassActivity = ClassActivityNoSql::whereClassId($classId)->count();

        if (empty($lmsName) || $lmsName->name != LmsSystemConstant::SIS || $amountClassActivity == 0) {
            throw new BadRequestException(
                ['message' => __("classActivity.export_score")], new Exception()
            );
        }

        return (new ClassActivityTemplateExport($classId))->download();
    }

    public function getTemplateActivityScore(int $id): BinaryFileResponse
    {
        return response()->download(storage_path('/template/ActivityScore/Activity_Score.xlsx'));
    }

    public function getViaClassId(int $id): JsonResponse
    {
        return response()->json($this->service->getViaClassId($id));
    }

}
