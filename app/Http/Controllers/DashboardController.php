<?php
/**
 * @Author Edogawa Conan
 * @Date   Aug 29, 2021
 */

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JetBrains\PhpStorm\Pure;

class DashboardController
{
    public DashboardService $service;

    #[Pure]
    public function __construct()
    {
        $this->service = new DashboardService();
    }

    function getSyntheticForDashBoard(): JsonResponse
    {
        return response()->json($this->service->getSyntheticForDashBoard());
    }

    function getPercentageGender(): JsonResponse
    {
        return response()->json($this->service->getPercentageGender());
    }

    function getPercentageGrade(): JsonResponse
    {
        return response()->json($this->service->getPercentageGrade());
    }

    function getStudentsOverview(): JsonResponse
    {
        return response()->json($this->service->getStudentsOverview());
    }

    function getClassesOverview(): JsonResponse
    {
        return response()->json($this->service->getClassesOverview());
    }

    function getCpaSummary(Request $request): JsonResponse
    {
        return response()->json($this->service->getCpaSummary($request));
    }
}
