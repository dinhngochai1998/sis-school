<?php


namespace App\Http\Controllers;


use App\Services\GraduationCategorySubjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use YaangVu\LaravelBase\Controllers\BaseController;

class GraduationCategorySubjectController extends BaseController
{
    public function __construct()
    {
        $this->service = new GraduationCategorySubjectService();
        parent::__construct();
    }

    public function assignStudents(int $id, Request $request): JsonResponse
    {
        return response()->json($this->service->getUserAcademicPlan($id, $request));
    }

    public function getUserAcademicPlan(string $uuId, int $programId): JsonResponse
    {
        return response()->json($this->service->getUserAcademicPlan($uuId, $programId));
    }
}
