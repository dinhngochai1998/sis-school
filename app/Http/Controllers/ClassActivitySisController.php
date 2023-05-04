<?php

namespace App\Http\Controllers;

use App\Services\ClassActivitySisService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\LaravelBase\Controllers\BaseController;
use YaangVu\SisModel\App\Models\impl\ClassActivityNoSql;

class ClassActivitySisController extends BaseController
{
    public function __construct()
    {
        $this->service = new ClassActivitySisService();
        parent::__construct();
    }

    public function setupParameter(Request $request, int $classId): JsonResponse
    {
        $classActivity = ClassActivityNoSql::whereClassId($classId)->first();
        if (!(empty($classActivity->source) || $classActivity->source == LmsSystemConstant::SIS || empty($classActivity))) {
            throw new BadRequestException(
                ['message' => __("classActivity.validate_class_id")], new Exception()
            );
        }

        return response()->json($this->service->setUpParameter($request, $classId));
    }

    public function addActivity(Request $request, $classId): JsonResponse
    {
        return response()->json($this->service->addActivity($classId, $request));
    }

    public function deleteActivity(Request $request, $classId): JsonResponse
    {
        return response()->json($this->service->deleteActivity($classId, $request));
    }

    public function updateActivity(Request $request, $classId): JsonResponse
    {
        return response()->json($this->service->updateActivity($request, $classId));
    }

    public function updateScoreToClassActivity(Request $request, $classId): JsonResponse
    {
        return response()->json($this->service->updateScoreToClassActivity($request, $classId));
    }

    public function syncStudentAssignmentToClassActivitySis()
    {
        return response()->json($this->service->syncStudentAssignmentToClassActivitySis());
    }
}
