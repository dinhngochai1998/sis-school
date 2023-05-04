<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public ReportService $service;

    public function __construct()
    {
        $this->service = new ReportService();
    }

    /**
     * Get attendance percentage period report for bar chart
     *
     * @Author yaangvu
     * @Date   Aug 11, 2021
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    function getAttendancePercentage(Request $request): JsonResponse
    {
        return response()->json($this->service->getAttendancePercentage($request));
    }

    /**
     * Get attendance summary report
     *
     * @Author yaangvu
     * @Date   Aug 11, 2021
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    function getAttendanceSummary(Request $request): JsonResponse
    {
        return response()->json($this->service->getAttendanceSummary($request));
    }

    /**
     * Get List students in Top Score GPA
     *
     * @Author yaangvu
     * @Date   Aug 11, 2021
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    function getTopGpaStudents(Request $request): JsonResponse
    {
        return response()->json($this->service->getTopGpaStudents($request));
    }

    /**
     * Get list of top GPA
     *
     * @Author yaangvu
     * @Date   Aug 19, 2021
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    function getTopGpa(Request $request): JsonResponse
    {
        return response()->json($this->service->getTopGpa($request));
    }

    /**
     * get chart pipe attendance
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    function getChartPipeAttendance(Request $request): JsonResponse
    {
        return response()->json($this->service->getChartPipeAttendance($request));
    }

    function getStudentForAttendanceReport(string $id, Request $request): JsonResponse
    {
        return response()->json($this->service->getStudentForAttendanceReport($id, $request));
    }

    function getAttendanceTopPresent(Request $request): JsonResponse
    {
        return response()->json($this->service->getAttendanceTopPresent($request));
    }

    /**
     * Get Student Summary
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    function getGpaSummary(Request $request): JsonResponse
    {
        return response()->json($this->service->getGpaSummary($request));
    }

    /**
     * Get Score Summary
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    function getScoreSummary(Request $request): JsonResponse
    {
        return response()->json($this->service->getScoreSummary($request));
    }

    /**
     * Get Score Grade Letter
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    function getScoreGradeLetter(Request $request): JsonResponse
    {
        return response()->json($this->service->getScoreGradeLetter($request));
    }

    /**
     * Get Score Course Grade
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    function getScoreCourseGrade(Request $request): JsonResponse
    {
        return response()->json($this->service->getScoreCourseGrade($request));
    }

    public function getStatusDetailsDailyAttendance(Request $request): JsonResponse
    {
        return response()->json($this->service->getStatusDetailsDailyAttendance($request));
    }

    public function getStatusDetailAttendancesByClass(Request $request): JsonResponse
    {
        return response()->json($this->service->getStatusDetailAttendancesByClass($request));
    }

    public function getStatusChartTaskManagement(Request $request): JsonResponse
    {
        return response()->json($this->service->getStatusChartTaskManagement($request));
    }

    public function getTimelinessChartTaskManagement(Request $request): JsonResponse
    {
        return response()->json($this->service->getTimelinessChartTaskManagement($request));
    }

    public function getCommunicationLog(Request $request): JsonResponse
    {
        return response()->json($this->service->getCommunicationLog($request));
    }
}
