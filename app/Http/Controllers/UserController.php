<?php
/**
 * @Author Admin
 * @Date   Mar 22, 2022
 */

namespace App\Http\Controllers;

use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use YaangVu\LaravelBase\Controllers\BaseController;

class UserController extends BaseController
{
    public function __construct()
    {
        $this->service = new UserService();
        parent::__construct();
    }

    public function getUserDetailSat($id): JsonResponse
    {
        return response()->json($this->service->getUserDetailSat($id));
    }

    public function getUserDetailPhysicalPerformance($id): JsonResponse
    {
        return response()->json($this->service->getUserDetailPhysicalPerformance($id));
    }

    public function getUserDetailIelts($id): JsonResponse
    {
        return response()->json($this->service->getUserDetailIelts($id));
    }


    public function getUserDetailAct(): JsonResponse
    {
        return response()->json($this->service->getUserDetailAct());
    }


    public function getAllPrimaryTeacherAssignmentByTermId($termId): JsonResponse
    {
        return response()->json($this->service->getAllPrimaryTeacherAssignmentByTermId($termId));
    }

    public function getStudentByClassId($classId) {
        return response()->json($this->service->getStudentByClassId($classId));
    }
}