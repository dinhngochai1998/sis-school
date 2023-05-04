<?php

namespace App\Http\Controllers;

use App\Services\ZoomMeetingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;
use YaangVu\LaravelBase\Controllers\BaseController;

class ZoomMeetingController extends BaseController
{
    public function __construct()
    {
        $this->service = new ZoomMeetingService();
        parent::__construct();
    }

    /**
     * @throws Throwable
     */
    public function addScheduledMeeting(Request $request): JsonResponse
    {
        return response()->json($this->service->addScheduledMeeting($request));
    }

    /**
     * @throws Throwable
     */
    public function updateScheduledMeeting(Request $request, $id): JsonResponse
    {
        return response()->json($this->service->updateScheduledMeeting($request, $id));
    }

    /**
     * @throws Throwable
     */
    public function deleteScheduledMeeting(Request $request,$id): JsonResponse
    {
        return response()->json($this->service->deleteZoomMeeting($request,$id));
    }

    /**
     * @Author apple
     * @Date   Jun 15, 2022
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getAllZoomMeeting(Request $request): JsonResponse
    {
        return response()->json($this->service->getAllZoomMeeting($request));
    }

    /**
     * @Author apple
     * @Date   Jun 15, 2022
     *
     * @param $id
     *
     * @return JsonResponse
     */
    public function getZoomMeetingById($id): JsonResponse
    {
        return response()->json($this->service->getZoomMeetingById($id));
    }

    /**
     * @Author apple
     * @Date   Jun 15, 2022
     *
     * @param $id
     *
     * @return JsonResponse
     */
    public function getParticipantByZoomMeeting($id): JsonResponse
    {
        return response()->json($this->service->getParticipantByZoomMeeting($id));
    }

    /**
     * @throws Throwable
     */
    public function addRecurringMeeting(Request $request): JsonResponse
    {
        return response()->json($this->service->addRecurringMeeting($request));
    }

    /**
     * @throws Throwable
     */
    public function updateRecurringMeeting(Request $request, $id): JsonResponse
    {
        return response()->json($this->service->updateRecurringMeeting($request, $id));
    }

    /**
     * @Description
     *
     * @Author im.phien
     * @Date   Jun 24, 2022
     *
     * @param Request $request
     * @param         $id
     *
     * @return JsonResponse
     * @throws Throwable
     */
    public function generateLinkZoomMeetingViaMeetingId(Request $request, $id): JsonResponse
    {
        return response()->json($this->service->generateLinkZoomMeetingViaMeetingId($request, $id));
    }

    /**
     * @throws Throwable
     */
    public function updateZoomMeeting(Request $request, $id): JsonResponse
    {
        return response()->json($this->service->updateZoomMeeting($request, $id));
    }

    /**
     * @Description
     *
     * @Author im.phien
     * @Date   Jun 28, 2022
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function hookDataZoomMeeting(Request $request): JsonResponse
    {
        return response()->json($this->service->hookDataZoomMeeting($request));
    }
}
