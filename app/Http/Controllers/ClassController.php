<?php

namespace App\Http\Controllers;

use App\Services\CalendarService;
use App\Services\ClassAssignmentService;
use App\Services\ClassService;
use App\Services\UserService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use YaangVu\LaravelBase\Controllers\BaseController;

class ClassController extends BaseController
{
    public function __construct()
    {
        $this->service = new ClassService();
        parent::__construct();
    }

    public function assignStudents(int $id, Request $request): JsonResponse
    {
        return response()->json($this->service->assignStudents($id, $request));
    }

    public function assignTeachers(int $id, Request $request): JsonResponse
    {
        return response()->json($this->service->assignTeachers($id, $request));
    }

    public function unAssignStudents(int $id, Request $request): JsonResponse
    {
        return response()->json($this->service->unAssignStudents($id, $request));
    }

    public function getAssignableStudents(int $id): JsonResponse
    {
        return response()->json((new UserService())->getAssignableStudents($id));
    }

    public function getStudentViaClassId(int $id, Request $request): JsonResponse
    {
        $userService = new UserService();

        return response()->json($userService->getStudentViaClassId($id, $request));
    }

    public function copyClass(int $id, Request $request): JsonResponse
    {
        return response()->json($this->service->copyClass($id, $request));
    }

    public function concludeClass(int $id): JsonResponse
    {
        return response()->json($this->service->concludeClass($id));
    }

    public function getClassesInProcess(string $uuid, Request $request): JsonResponse
    {
        return response()->json($this->service->getClassesInProcess($uuid, $request));
    }

    public function getListClassForCurrentUser(): JsonResponse
    {
        return response()->json($this->service->getListClassForCurrentUser());
    }

    public function getCalendarViaClassId(int $id): JsonResponse
    {
        $calendarService = new CalendarService();
        return response()->json($calendarService->getCalendarViaClassId($id));
    }

    public function updateStatusAssign(int|string $id,Request $request): JsonResponse
    {
        $classAssignmentService = new ClassAssignmentService();

        return response()->json($classAssignmentService->updateStatusAssign($id, $request));
    }

    public function downloadEnrollStudentTemplate(Request $request): BinaryFileResponse
    {
        return response()->download(storage_path('template/EnrollStudent/Template_enroll_student.xlsx'));
    }

    /**
     * @throws Exception
     */
    public function importEnrollStudent(Request $request): JsonResponse
    {
        return response()->json($this->service->importEnrollStudent($request));
    }

    /**
     * @throws Exception
     */
    public function updateStatusAssignFromClassesViaStudentId(int|string $id, Request $request): JsonResponse
    {
        $classAssignmentService = new ClassAssignmentService();

        return response()->json($classAssignmentService->updateStatusAssignFromClassesViaStudentId($id, $request));
    }

    /**
     * @throws Exception
     */
    public function unAssignStudentFromClassesViaStudentId(int|string $id, Request $request): JsonResponse
    {
        return response()->json($this->service->unAssignStudentFromClassesViaStudentId($id, $request));
    }

    public function getClassByTermId($id, Request $request): JsonResponse
    {
        return response()->json($this->service->getClassByTermId($id, $request));
    }

    public function getNumBerStudentByClassId( int $id, Request $request): JsonResponse
    {
        return response()->json($this->service->getNumBerStudentByClassId($id, $request));
    }
}
