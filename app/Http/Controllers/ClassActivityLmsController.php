<?php
/**
 * @Author kyhoang
 * @Date   May 26, 2022
 */

namespace App\Http\Controllers;

use App\Services\ClassActivityLmsService;
use App\Services\ClassService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;
use YaangVu\LaravelBase\Controllers\BaseController;

class ClassActivityLmsController extends BaseController
{
    public function __construct() {
        $this->service = new ClassActivityLmsService();
        parent::__construct();
    }

    public function setupParameter(Request $request, int $classId): JsonResponse
    {
        return response()->json($this->service->setUpParameter($request,$classId));
    }

    public function getViaClassId(int $classId): JsonResponse
    {
        $class = (new ClassService())->get($classId);
        return response()->json($this->service->getLmsClassViaClassSQL($class));
    }

    /**
     * @throws Throwable
     */
    public function updateActivityScore(Request $request , int $classId): JsonResponse
    {
        return response()->json($this->service->updateActivityScore($request,$classId));
    }

    /**
     * @Description
     *
     * @Author kyhoang
     * @Date   Jun 02, 2022
     *
     * @param int $classId
     *
     * @return JsonResponse
     * @throws Throwable
     */
    public function removeAllParameters(int $classId): JsonResponse
    {
        return response()->json($this->service->removeAllParameters($classId));
    }

    public function syncStudentAssignmentToClassActivity(): JsonResponse
    {
        return response()->json($this->service->syncStudentAssignmentToClassActivity());
    }
}
