<?php


namespace App\Http\Controllers;


use App\Services\CalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;
use YaangVu\LaravelBase\Controllers\BaseController;
use const DCarbone\Go\HTTP\StatusCreated;

class CalendarController extends BaseController
{
    public function __construct()
    {
        $this->service = new CalendarService();
        parent::__construct();
    }

    public function index(): JsonResponse
    {
        return response()->json($this->service->getAllWithoutPaginate());
    }

    public function addSchoolEvent(Request $request): JsonResponse
    {
        return response()->json($this->service->addSchoolEvent($request))->setStatusCode(StatusCreated);
    }

    /**
     * @throws Throwable
     */
    public function addClassSchedule(Request $request): JsonResponse
    {
        return response()->json($this->service->addClassSchedule($request))->setStatusCode(StatusCreated);
    }

    /**
     * @throws Throwable
     */
    public function updateSchoolEvent(string $id, Request $request): JsonResponse
    {
        return response()->json($this->service->updateSchoolEvent($id, $request));
    }

    public function updateSingleEvent(string $id, Request $request): JsonResponse
    {
        return response()->json($this->service->updateSingleEvent($id, $request));
    }

    /**
     * @throws Throwable
     */
    public function updateClassSchedule(string $id, Request $request): JsonResponse
    {
        return response()->json($this->service->updateClassSchedule($id, $request));
    }

    public function getAllWithoutPaginateForCurrentUser(): JsonResponse
    {
        return response()->json($this->service->getAllWithoutPaginateForCurrentUser());
    }

    /**
     * @Description
     *
     * @Author hoang
     * @Date   Apr 17, 2022
     *
     * @return JsonResponse
     */
    public function syncTerms(): JsonResponse
    {
        return response()->json($this->service->syncTerms());
    }

    public function deleteCalendarsTypeVideoConference(string $id, Request $request): JsonResponse
    {
        return response()->json($this->service->deleteCalendarsTypeVideoConference($id, $request));
    }

    /**
     * @Description
     *
     * @Author im.phien
     * @Date   Sep 13, 2022
     *
     * @param int     $zoomMeetingId
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getCalendarZoomMeetingViaZoomMeetingId(int $zoomMeetingId, Request $request): JsonResponse
    {
        return response()->json($this->service->getCalendarZoomMeetingViaZoomMeetingId($zoomMeetingId, $request));
    }

    public function getAllWithoutPaginateForDashboard(): JsonResponse
    {
        return response()->json($this->service->getAllWithoutPaginateForDashboard());
    }

    public function getAllWithoutPaginateForCurrentUserDashboard(): JsonResponse
    {
        return response()->json($this->service->getAllWithoutPaginateForCurrentUserDashboard());
    }

    /**
     * @throws Throwable
     */
    public function cancelCalendarsTypeVideoConference(string $id, Request $request): JsonResponse
    {
        return response()->json($this->service->cancelCalendarsTypeVideoConference($id, $request));
    }

    public function deleteCalendarsTypeCanceled(string $id): JsonResponse
    {
        return response()->json($this->service->deleteCalendarsTypeCanceled($id));
    }
}
