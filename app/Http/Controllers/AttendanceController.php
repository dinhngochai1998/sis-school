<?php

namespace App\Http\Controllers;

use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;
use YaangVu\LaravelBase\Controllers\BaseController;

class AttendanceController extends BaseController
{
    public function __construct()
    {
        $this->service = new AttendanceService();
        parent::__construct();
    }

    public function getAllViaCalendarId(string $id): JsonResponse
    {
        return response()->json($this->service->getAllViaCalendarId($id));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->service->insertBatch($request))->setStatusCode(ResponseAlias::HTTP_CREATED);
    }

    function getViaUserIdAndClassId(string|int $userId, int $classId): JsonResponse
    {
        return response()->json($this->service->getViaUserIdAndClassId($userId,$classId));
    }

    public function getAttendancePercentStatus(Request $request): JsonResponse
    {
        return response()->json($this->service->getAttendancePercentStatus($request));
    }

    public function getAttendancePercentStudent(Request $request): JsonResponse
    {
        return response()->json($this->service->getAttendancePercentStudent($request));
    }

    public function getViaStudentId(int|string $id): JsonResponse
    {
        return response()->json($this->service->getViaStudentId($id));
    }
}
