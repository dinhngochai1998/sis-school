<?php

namespace App\Http\Controllers;

use App\Services\AttendanceLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use YaangVu\LaravelBase\Controllers\BaseController;

class AttendanceLogController extends BaseController
{
    public function __construct()
    {
        $this->service = new AttendanceLogService();
        parent::__construct();
    }

    /**
     * @Author apple
     * @Date   Jun 27, 2022
     *
     * @param Request $request
     * @param         $id
     *
     * @return JsonResponse
     */
    public function getAttendanceLogByZoomMeetingId(Request $request, $id): JsonResponse
    {
        return response()->json($this->service->getAttendanceLogByZoomMeetingId($request, $id));
    }

    /**
     * @Author apple
     * @Date   Jun 27, 2022
     *
     * @param Request $request
     * @param         $id
     *
     * @return JsonResponse
     */
    public function getReportStatistics(Request $request, $id): JsonResponse
    {
        return response()->json($this->service->getReportStatistics($request, $id));
    }

    /**
     * @Author apple
     * @Date   Jun 30, 2022
     *
     * @param Request $request
     * @param         $id
     *
     * @return JsonResponse
     */
    public function getDateByZoomMeetingId(Request $request, $id): JsonResponse
    {
        return response()->json($this->service->getDateByZoomMeetingId($request, $id));
    }

    /**
     * @Author apple
     * @Date   Jun 30, 2022
     *
     * @param Request $request
     * @param int     $zoomMeetingId
     *
     * @return JsonResponse
     */
    public function updateAttendanceLogsViaZoomMeetingId(Request $request, int $zoomMeetingId): JsonResponse
    {
        return response()->json($this->service->updateAttendanceLogsViaZoomMeetingId($request, $zoomMeetingId));
    }

    /**
     * @Description
     *
     * @Author im.phien
     * @Date   Sep 20, 2022
     *
     * @param string $group
     *
     * @return JsonResponse
     */
    public function getAttendanceLogsViaGroupCalendar(string $group): JsonResponse
    {
        return response()->json($this->service->getAttendanceLogsViaGroupCalendar($group));
    }


}
