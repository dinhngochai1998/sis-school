<?php
/**
 * @Author apple
 * @Date   Jun 13, 2022
 */

namespace App\Services;

use App\Helpers\ZoomMeetingHelper;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Storage;
use Throwable;
use YaangVu\Constant\ClassAssignmentConstant;
use YaangVu\Constant\GuestObjectInviteToZoomConstant;
use YaangVu\Constant\JobConstant;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Constant\StatusAttendanceLogConstant;
use YaangVu\Constant\StatusJoinMeetingBeforeHostConstant;
use YaangVu\Constant\UserJoinZoomMeetingConstant;
use YaangVu\Constant\ZoomMeetingEventConstant;
use YaangVu\Constant\ZoomMeetingSettingConstant;
use YaangVu\Constant\ZoomMeetingTypeConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\ForbiddenException;
use YaangVu\Exceptions\NotFoundException;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelAws\S3Service;
use YaangVu\LaravelBase\Helpers\QueryHelper;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Constants\CalendarRepeatTypeConstant;
use YaangVu\SisModel\App\Constants\CalendarTypeConstant;
use YaangVu\SisModel\App\Models\impl\AttendanceLogSQL;
use YaangVu\SisModel\App\Models\impl\AttendanceSQL;
use YaangVu\SisModel\App\Models\impl\CalendarNoSQL;
use YaangVu\SisModel\App\Models\impl\ClassAssignmentSQL;
use YaangVu\SisModel\App\Models\impl\JobNoSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Models\impl\UserSQL;
use YaangVu\SisModel\App\Models\impl\ZoomHostSQL;
use YaangVu\SisModel\App\Models\impl\ZoomMeetingSQL;
use YaangVu\SisModel\App\Models\impl\ZoomParticipantSQL;
use YaangVu\SisModel\App\Models\impl\ZoomSettingSQL;
use YaangVu\SisModel\App\Providers\RoleServiceProvider;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;


class ZoomMeetingService extends BaseService
{
    use RoleAndPermissionTrait;

    protected array           $userJoinZoomMeeting     = UserJoinZoomMeetingConstant::ALL;
    protected array           $zoomMeetingType         = ZoomMeetingTypeConstant::ALL;
    protected array           $joinMeetingBeforeHost   = StatusJoinMeetingBeforeHostConstant::ALL;
    protected array           $guestObjectInviteToZoom = GuestObjectInviteToZoomConstant::ALL;
    protected array           $repeatCalendar          = [CalendarRepeatTypeConstant::WEEKLY, CalendarRepeatTypeConstant::DAILY];
    protected array           $dayOfWeek               = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    private ZoomMeetingHelper $zoomMeetingHelper;


    function createModel(): void
    {
        $this->model = new ZoomMeetingSQL();
    }

    function __construct()
    {
        parent::__construct();
        $this->zoomMeetingHelper = new ZoomMeetingHelper();
    }

    /**
     * @Author apple
     * @Date   Jun 14, 2022
     *
     * @param object $request
     *
     * @return bool
     * @throws Throwable
     */
    public function addScheduledMeeting(object $request): bool
    {
        $isTeacher = $this->hasAnyRole(RoleConstant::TEACHER);
        if (!($this->hasPermission(PermissionConstant::zoomMeeting(PermissionActionConstant::ADD)) || $isTeacher))
            throw new ForbiddenException(__('role.forbidden'), new Exception());

        DB::beginTransaction();

        $this->validateScheduleZoomMeeting($request);

        try {
            $this->_handleRequest($request);
            $model = $this->add($request);

            // push to zoom_meeting ui
            $this->addToZooMeetingUi($request, $model);

            $this->postScheduleMeeting($request, $model);


            DB::commit();

            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }

    }

    public function postScheduleMeeting(object $request, Model $model): bool
    {
        // delete job send email
        JobNoSQL::query()->where('zoom_meeting_id', $model->id)->delete();

        // insert participant to zoom
        $participants = (new ZoomParticipantService())->insertParticipantToZoomMeeting($request, $model->id);

        $userUuids = array_column($participants, 'user_uuid');

        // add event to calendar
        (new CalendarService())->addScheduleMeeting($request, $model, $userUuids);

        return true;
    }

    /**
     * @Author apple
     * @Date   Jun 16, 2022
     *
     * @param object $request
     */
    public function validateScheduleZoomMeeting(object $request)
    {
        $rule = [
            'title'                        => "required",
            'password'                     => "required|max:10|regex:/^[A-Za-z0-9_@*-]+$/",
            "zoom_meeting_type"            => "required|in:" . ZoomMeetingTypeConstant::SCHEDULE_MEETING,
            'duration'                     => "required|integer|min:1|max:1000",
            'notification_before'          => "numeric|min:0",
            'join_before_host'             => "required|boolean",
            'participant_join_before_host' => "sometimes|in:" . implode(',', $this->joinMeetingBeforeHost),
            'student_attendance'           => "required|boolean",
            "type_guest"                   => "required|in:" . implode(',', $this->guestObjectInviteToZoom),
            'host_uuid'                    => "required|exists:zoom_hosts,uuid",
            "calendar.start_date_time"     => "required|date_format:Y-m-d H:i|after_or_equal:now",
        ];

        if ($request->type_guest == GuestObjectInviteToZoomConstant::USER_INFORMATION) {
            $rule["student_uuid"] = "required|array";
            $rule["student_uuid.*"]
                                  = "required|exists:mongodb.users,uuid,sc_id," . SchoolServiceProvider::$currentSchool->uuid;

        } else {
            $rule["class_id"]   = "required|array";
            $rule["class_id.*"] = "sometimes|exists:classes,id";
        }

        $messages = [
            'password.regex' => __("ZoomMeeting.password"),
            'after_or_equal' => __('ZoomMeeting.zoom_meeting_start_date_time'),
        ];

        $this->doValidate($request, $rule, $messages);
    }

    /**
     * @Author apple
     * @Date   Jun 16, 2022
     *
     * @param object $request
     *
     * @return bool
     * @throws Throwable
     */
    public function addRecurringMeeting(object $request): bool
    {
        $isTeacher = $this->hasAnyRole(RoleConstant::TEACHER);
        if (!($this->hasPermission(PermissionConstant::zoomMeeting(PermissionActionConstant::ADD)) || $isTeacher))
            throw new ForbiddenException(__('role.forbidden'), new Exception());

        DB::beginTransaction();
        $this->validateRecurringMeeting($request);

        if ($request->calendar['repeat'] == CalendarRepeatTypeConstant::DAILY)
            $this->validateRecurringWithDailyMeeting($request);
        else
            $this->validateRecurringWithWeeklyMeeting($request);

        try {
            $this->_handleRequest($request);
            $model = $this->add($request);

            // push to zoom_meeting ui
            $this->addToZooMeetingUi($request, $model);

            $this->postRecurringMeeting($request, $model);

            DB::commit();

            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @Author apple
     * @Date   Jun 15, 2022
     *
     * @param object $request
     * @param Model  $model
     *
     * @return bool
     */
    public function postRecurringMeeting(object $request, Model $model): bool
    {
        // delete job send email
        JobNoSQL::query()->where('zoom_meeting_id', $model->id)->delete();

        // insert participant to zoom
        $participants = (new ZoomParticipantService())->insertParticipantToZoomMeeting($request, $model->id);
        $userUuids    = array_column($participants, 'user_uuid');

        $keyHostUuid = array_search($request->host_uuid, $userUuids);
        if ($keyHostUuid)
            unset($userUuids[$keyHostUuid]);

        // add event to calendar
        if ($request->calendar['repeat'] == CalendarRepeatTypeConstant::DAILY)
            (new CalendarService())->addRecurringWithDailyMeeting($request, $model, $userUuids);
        else
            (new CalendarService())->addRecurringWithWeeklyMeeting($request, $model, $userUuids);

        return true;
    }

    public function validateRecurringMeeting(object $request)
    {
        $rule = [
            'title'                        => "required",
            'password'                     => "required|max:10|regex:/^[A-Za-z0-9_@*-]+$/",
            "zoom_meeting_type"            => "required|in:" . ZoomMeetingTypeConstant::RECURRING_MEETING,
            'duration'                     => "required|integer|min:1|max:1000",
            'notification_before'          => "numeric|min:0",
            'join_before_host'             => "required|boolean",
            'participant_join_before_host' => "sometimes|in:" . implode(',', $this->joinMeetingBeforeHost),
            'student_attendance'           => "required|boolean",
            "type_guest"                   => "required|in:" . implode(',', $this->guestObjectInviteToZoom),
            'host_uuid'                    => "required|exists:zoom_hosts,uuid",
            "calendar.repeat"              => "required|in:" . implode(',', $this->repeatCalendar)
        ];

        if ($request->type_guest == GuestObjectInviteToZoomConstant::USER_INFORMATION) {
            $rule["student_uuid.*"]
                                  = "required|exists:mongodb.users,uuid,sc_id," . SchoolServiceProvider::$currentSchool->uuid;
            $rule["student_uuid"] = "required|array";

        } else {
            $rule["class_id"]   = "required|array";
            $rule["class_id.*"] = "sometimes|exists:classes,id";
        }

        $messages = [
            'password.regex' => __("ZoomMeeting.password"),
        ];

        $this->doValidate($request, $rule, $messages);
    }

    public function validateRecurringWithDailyMeeting(object $request)
    {
        $this->doValidate($request, [
            "calendar.start"     => "required|date_format:Y-m-d|after_or_equal:" . Carbon::now()->format('Y-m-d'),
            "calendar.end"       => "required|date_format:Y-m-d|after:calendar.start",
            "calendar.from_time" => "required|date_format:H:i",
        ],
                          ['after_or_equal' => __('ZoomMeeting.zoom_meeting_start_date'), 'after' => __('ZoomMeeting.zoom_meeting_end_date')]);
    }

    public function validateRecurringWithWeeklyMeeting(object $request)
    {
        $this->doValidate($request, [
            'calendar.start'                   => 'required|date_format:Y-m-d|after_or_equal:' . Carbon::now()
                                                                                                       ->format('Y-m-d'),
            'calendar.end'                     => 'required|date_format:Y-m-d|after:calendar.start',
            'calendar.from_time'               => 'required|date_format:H:i',
            'calendar.repeat_on'               => 'nullable|array',
            'calendar.repeat_on.*.day_of_week' => 'required|in:' . implode(',', $this->dayOfWeek),
        ],
                          ['after_or_equal' => __('ZoomMeeting.zoom_meeting_start_date'), 'after' => __('ZoomMeeting.zoom_meeting_end_date')]);
    }


    /**
     * @Author apple
     * @Date   Jun 14, 2022
     *
     * @param object $request
     * @param int    $id
     *
     * @return bool
     * @throws Throwable
     */

    public function updateScheduledMeeting(object $request, int $id): bool
    {
        DB::beginTransaction();

        $zoomMeeting = $this->get($id);

        $this->validateScheduleZoomMeeting($request);

        try {

            // delete participant by zoomUuid
            ZoomParticipantSQL::query()->where('zoom_meeting_id', $zoomMeeting->id)->delete();

            // delete attendance log
            (new AttendanceLogService())->deleteAttendanceLogByZoomMeetingId($request, $zoomMeeting->id);

            $this->updateStatusCalendarAndDeleteCalendarFuture($id);

            $this->_handleRequest($request);
            $model = $this->update($id, $request);

            // push to zoom_meeting ui
            $this->updateToZoomMeetingUi($request, $zoomMeeting->zoom_meeting_ui_id);

            $this->postScheduleMeeting($request, $model);

            DB::commit();

            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }

    }

    /**
     * @throws Throwable
     */
    public function updateRecurringMeeting(object $request, int $id): bool
    {
        DB::beginTransaction();
        $zoomMeeting = $this->get($id);
        $this->validateRecurringMeeting($request);

        if ($request->calendar['repeat'] == CalendarRepeatTypeConstant::DAILY)
            $this->validateUpdateRecurringWithDailyMeeting($request, $id);
        else
            $this->validateUpdateRecurringWithWeeklyMeeting($request, $id);


        try {
            // delete participant by zoomUuid
            ZoomParticipantSQL::query()->where('zoom_meeting_id', $zoomMeeting->id)->delete();

            // delete attendance log
            (new AttendanceLogService())->deleteAttendanceLogByZoomMeetingId($request, $zoomMeeting->id);

            $this->updateStatusCalendarAndDeleteCalendarFuture($id);

            $this->_handleRequest($request);
            $model = $this->update($id, $request);

            // push to zoom_meeting ui
            $this->updateToZoomMeetingUi($request, $zoomMeeting->zoom_meeting_ui_id);

            $this->postRecurringMeeting($request, $model);

            DB::commit();

            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @throws Throwable
     */
    public function updateZoomMeeting(object $request, $id): bool
    {
        $isTeacher = $this->hasAnyRole(RoleConstant::TEACHER);
        if (!($this->hasPermission(PermissionConstant::zoomMeeting(PermissionActionConstant::EDIT)) || $isTeacher))
            throw new ForbiddenException(__('role.forbidden'), new Exception());

        $this->doValidate($request, [
            'zoom_meeting_type' => "required"
        ]);

        if ($request->zoom_meeting_type == ZoomMeetingTypeConstant::SCHEDULE_MEETING) {
            $this->updateScheduledMeeting($request, $id);
        } else {
            $this->updateRecurringMeeting($request, $id);
        }

        return true;
    }


    /**
     * @Author apple
     * @Date   Jun 14, 2022
     *
     * @param Request $request
     * @param int     $id
     *
     * @return bool
     */
    public function deleteZoomMeeting(Request $request, int $id): bool
    {
        $isTeacher = $this->hasAnyRole(RoleConstant::TEACHER);
        if (!($this->hasPermission(PermissionConstant::zoomMeeting(PermissionActionConstant::DELETE)) || $isTeacher))
            throw new ForbiddenException(__('role.forbidden'), new Exception());

        $zoomMeeting = $this->get($id);

        $totalCalendar = CalendarNoSQL::query()->where('zoom_meeting_id', $id)->count();

        $calendars = CalendarNoSQL::query()->where('zoom_meeting_id', $id)
                                  ->where('start', '>', Carbon::now())
                                  ->get();

        /**
         * TH1 : all start calendar < now -> throw message
         * TH2: start calendar < now vs > now -> delete calendar has start > now and not delete room_meetings vs zoom_participants
         * TH3: all start calendar > now ->  delete room_meetings and zoom_participants and calendar
         */


        if (count($calendars) != 0 && count($calendars) < $totalCalendar) {
            CalendarNoSQL::query()->where('zoom_meeting_id', $id)
                         ->where('start', '>', Carbon::now())
                         ->delete();

        } elseif (count($calendars) == $totalCalendar) {
            // delete calendar
            CalendarNoSQL::query()->where('zoom_meeting_id', $id)->delete();

            // delete participant by zoomUuid
            ZoomParticipantSQL::query()->where('zoom_meeting_id', $zoomMeeting->id)->delete();

            // delete zoom_meetings
            $zoomMeeting->delete();
        } else {
            throw new BadRequestException(
                ['message' => __("ZoomMeeting.delete_zoom_meeting")], new Exception()
            );
        }

        // delete job send email
        JobNoSQL::query()->where('zoom_meeting_id', $id)->delete();

        // delete attendance log by zoomMeetingId
        (new AttendanceLogService())->deleteAttendanceLogByZoomMeetingId($request, $id);

        // push to zoomUi and delete zoomMeeting
        $zoomHost = ZoomParticipantSQL::query()
                                      ->join('zoom_hosts', 'zoom_participants.user_uuid', '=', 'zoom_hosts.uuid')
                                      ->where('zoom_participants.zoom_meeting_id', $id)
                                      ->where('user_join_meeting', UserJoinZoomMeetingConstant::HOST)
                                      ->first();

        $token = ZoomSettingSQL::query()->where('id', $zoomHost->zoom_meeting_id)->first()->token;

        $this->zoomMeetingHelper->deleteZoomMeeting($zoomMeeting->zoom_meeting_ui_id, $token);

        return true;
    }

    public function updateStatusCalendarAndDeleteCalendarFuture($zoomMeetingId)
    {
        // delete calendar
        CalendarNoSQL::query()->where('zoom_meeting_id', $zoomMeetingId)
                     ->where('start', '>', Carbon::now())
                     ->delete();

        // update status calendar past
        CalendarNoSQL::query()->where('zoom_meeting_id', $zoomMeetingId)
                     ->where('start', '<=', Carbon::now())
                     ->update([
                                  'status' => CalendarTypeConstant::PAST
                              ]);
    }

    /**
     * @Author apple
     * @Date   Jun 15, 2022
     *
     * @param object $request
     *
     * @return LengthAwarePaginator
     */
    public function getAllZoomMeeting(object $request): LengthAwarePaginator
    {
        $isTeacher = $this->hasAnyRole(RoleConstant::TEACHER);
        if (!($this->hasPermission(PermissionConstant::zoomMeeting(PermissionActionConstant::LIST)) || !$isTeacher))
            throw new ForbiddenException(__('role.forbidden'), new Exception());

        $classId = $request->class_id ? trim($request->class_id) : null;

        // validate role_id
        if (!$this->hasAnyRoleWithUser(RoleServiceProvider::$currentRole?->id)) {
            throw new BadRequestException(
                ['message' => __("role.validate_role_id")], new Exception()
            );
        }

        $hostUuid  = [];
        $searchKey = $request->search_key ? trim($request->search_key) : null;
        $fields    = is_array($request->fields) ? $request->fields : [];

        if (count($fields) > 0 && $searchKey) {
            $searchKey = strtolower($searchKey);
            $hostUuid  = ZoomHostSQL::query()->where(function (EBuilder $query) use ($searchKey, $fields) {
                foreach ($fields as $key => $field) {
                    if ($key == 0) {
                        $query->where(trim($field), 'LIKE', '%' . $searchKey . '%');
                        continue;
                    }
                    $query->orWhere(trim($field), 'LIKE', '%' . $searchKey . '%');
                }
            })->pluck('uuid')->toArray();
        }

        if (count($fields) > 0 && $searchKey && empty($hostUuid))
            return $this->queryHelper->removeParam('zoom_meeting_type')
                                     ->removeParam('title')
                                     ->removeParam('search_key')
                                     ->removeParam('fields')
                                     ->buildQuery($this->model)
                                     ->leftJoin('zoom_participants', 'zoom_participants.zoom_meeting_id',
                                                'zoom_meetings.id')
                                     ->leftJoin('users', 'zoom_participants.user_uuid', 'users.uuid')
                                     ->whereIn('zoom_participants.user_uuid', $hostUuid)
                                     ->paginate(QueryHelper::limit());

        $data = $this->queryHelper->removeParam('zoom_meeting_type')
                                  ->removeParam('title')
                                  ->removeParam('search_key')
                                  ->removeParam('fields')
                                  ->buildQuery($this->model)
                                  ->with([
                                             'hostZoomMeeting.host',
                                             'calendars' => function ($q) {
                                                 $q->where('status', CalendarTypeConstant::FUTURE);
                                             }
                                         ])
                                  ->leftJoin('zoom_participants', 'zoom_participants.zoom_meeting_id',
                                             'zoom_meetings.id')
                                  ->leftJoin('users', 'zoom_participants.user_uuid', 'users.uuid')
                                  ->when($classId, function ($q) use ($classId) {
                                      $q->where('zoom_participants.class_id', $classId);
                                  })
                                  ->when($hostUuid, function ($q) use ($hostUuid) {
                                      $q->whereIn('zoom_participants.user_uuid', $hostUuid);
                                  })
                                  ->when(RoleServiceProvider::$currentRole?->name == RoleConstant::TEACHER,
                                      function ($q) {
                                          $classIds = ClassAssignmentSQL::query()->where('user_id',
                                                                                         BaseService::currentUser()->id)
                                                                        ->whereIn('assignment',
                                                                                  ClassAssignmentConstant::TEACHER)
                                                                        ->pluck('class_id')
                                                                        ->toArray();
                                          $q->whereIn('zoom_participants.class_id', $classIds);
                                      })
                                  ->when(RoleServiceProvider::$currentRole?->name == RoleConstant::COUNSELOR,
                                      function ($q) {
                                          $q->where('zoom_participants.user_uuid', BaseService::currentUser()->uuid)
                                            ->where('zoom_meetings.type_guest',
                                                    GuestObjectInviteToZoomConstant::USER_INFORMATION);
                                      })
                                  ->select('zoom_meetings.*')
                                  ->orderBy('zoom_meetings.id', 'DESC')
                                  ->groupBy('zoom_meetings.id');
        try {
            return $data->paginate(QueryHelper::limit());

        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }


    /**
     * @Author apple
     * @Date   Jun 16, 2022
     *
     * @param int $id
     *
     * @return array|Model|Collection|EBuilder|null
     */
    public function getZoomMeetingById(int $id): array|Model|Collection|EBuilder|null
    {
        if (!($this->hasPermission(PermissionConstant::zoomMeeting(PermissionActionConstant::VIEW))))
            throw new ForbiddenException(__('role.forbidden'), new Exception());

        try {
            $dayOfWeek
                = ['Sunday' => 1, 'Monday' => 2, 'Tuesday' => 3, 'Wednesday' => 4, 'Thursday' => 5, 'Friday' => 6, 'Saturday' => 7];

            $data = $this->model->with([
                                           'hostZoomMeeting.host',
                                           'calendars' => function ($q) {
                                               $q->where('status', CalendarTypeConstant::FUTURE);
                                           }
                                       ])
                                ->findOrFail($id);

            if ($data->repeat == CalendarRepeatTypeConstant::WEEKLY) {

                $calendar = CalendarNoSQL::query()->where('zoom_meeting_id', $id)
                                         ->orderBy('created_at', 'DESC')
                                         ->first();
                $repeatOn = $calendar['raw_data']['repeat_on'];

                $data->{'repeat_on'} = array_column($repeatOn, 'day_of_week');

                $keyDay = [];
                foreach (array_column($repeatOn, 'day_of_week') as $value) {
                    if (!empty(($dayOfWeek[$value] ?? null)))
                        $keyDay[] = $dayOfWeek[$value];
                }

                $data->{'repeat_on_key'} = $keyDay;
            }

            return $data;

        } catch (ModelNotFoundException $e) {
            throw new NotFoundException(
                ['message' => __("not-exist", ['attribute' => __('entity')]) . ": $id"],
                $e
            );
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @Author apple
     * @Date   Jun 15, 2022
     *
     * @param int $id
     *
     * @return LengthAwarePaginator
     */
    public function getParticipantByZoomMeeting(int $id): LengthAwarePaginator
    {
        $zoomMeeting = $this->model::query()->where('id', $id)->first();
        $typeGuest   = $zoomMeeting->type_guest ?? null;

        if ($typeGuest == GuestObjectInviteToZoomConstant::USER_INFORMATION) {
            return ZoomParticipantSQL::query()
                                     ->with('user')
                                     ->where('zoom_meeting_id', $id)
                                     ->where('user_join_meeting', UserJoinZoomMeetingConstant::STUDENT)
                                     ->where('type_guest', GuestObjectInviteToZoomConstant::USER_INFORMATION)
                                     ->paginate(QueryHelper::limit());
        } else {
            return ZoomParticipantSQL::query()
                                     ->with('classes')
                                     ->where('zoom_meeting_id', $id)
                                     ->where('user_join_meeting', UserJoinZoomMeetingConstant::STUDENT)
                                     ->where('type_guest', GuestObjectInviteToZoomConstant::CLASSES)
                                     ->select('class_id', 'zoom_participants.*')
                                     ->distinct('class_id')
                                     ->paginate(QueryHelper::limit());
        }
    }

    public function _handleRequest(object $request): object
    {
        $repeat = $start = $end = $fromTime = "";
        if (($request->calendar['repeat'] ?? null) == null) {
            $dateTime = explode(' ', $request->calendar['start_date_time']);
            $repeat   = "";
            $start    = $end = $dateTime[0];
            $fromTime = $dateTime[1];
        } else {
            $repeat   = $request->calendar['repeat'];
            $start    = $request->calendar['start'];
            $end      = $request->calendar['end'];
            $fromTime = $request->calendar['from_time'];
        }

        if ($request instanceof Request) {
            $request->merge(
                [
                    'repeat'    => $repeat,
                    'start'     => $start,
                    'end'       => $end,
                    'from_time' => $fromTime
                ]
            );
        } else {
            $request->repeat    = $repeat;
            $request->start     = $start;
            $request->end       = $end;
            $request->from_time = $fromTime;
        }

        return $request;
    }

    public function addToZooMeetingUi(object $request, $model): bool
    {
        $requestZoomMeetingUi = $this->_handleZoomMeetingRequest($request);
        $host                 = ZoomHostSQL::query()->where('uuid', $request->host_uuid)->first();
        $hostId               = $host->host_id;
        $token                = ZoomSettingSQL::query()->where('id', $host->zoom_setting_id)->first()?->token;
        $zoomMeetingUi        = $this->zoomMeetingHelper->createZoomMeeting($hostId, $requestZoomMeetingUi, $token);

        $model->update([
                           'link_zoom'          => $zoomMeetingUi?->join_url,
                           'zoom_meeting_ui_id' => $zoomMeetingUi?->id,
                           'pmi'                => $zoomMeetingUi?->pmi
                       ]);

        return true;
    }

    public function updateToZoomMeetingUi(object $request, int $zoomMeetingId): bool
    {
        $requestZoomMeetingUi = $this->_handleZoomMeetingRequest($request);

        $host  = ZoomHostSQL::query()->where('uuid', $request->host_uuid)->first();
        $token = ZoomSettingSQL::query()->where('id', $host->zoom_setting_id)->first()?->token;

        $this->zoomMeetingHelper->updateZoomMeeting($zoomMeetingId, $requestZoomMeetingUi, $token);

        return true;
    }

    private function _handleZoomMeetingRequest(object $request): object
    {
        $calendar = $request->calendar ?? null;
        if (!$calendar) {
            $startDate     = Carbon::parse($request['start'] . ' ' . $request['from_time'])
                                   ->format('Y-m-d H:i:s');
            $startDateTime = self::setTzUTCViaDateAndMinutes($startDate);
            if (!empty($request['repeat'])) {
                $preSchedule = true;
                $type        = ZoomMeetingSettingConstant::RECURRING_MEETING_WITH_NO_FIXED_TIME;
                $recurrence  = $this->_handleRequestRecurringMeeting($request);
            } else {
                $type        = ZoomMeetingSettingConstant::SCHEDULE_MEETING;
                $preSchedule = false;
            }
        } else {
            if (isset($calendar['repeat'])) {
                $startDate     = Carbon::parse($calendar['start'] . ' ' . $calendar['from_time'])
                                       ->format('Y-m-d H:i:s');
                $startDateTime = self::setTzUTCViaDateAndMinutes($startDate);
                $preSchedule   = true;
                $type          = ZoomMeetingSettingConstant::RECURRING_MEETING_WITH_NO_FIXED_TIME;
                $recurrence    = $this->_handleRequestRecurringMeeting((object)$calendar);
            } else {
                $startDate     = Carbon::parse($calendar['start_date_time'])->format('Y-m-d H:i:s');
                $startDateTime = self::setTzUTCViaDateAndMinutes($startDate);
                $type          = ZoomMeetingSettingConstant::SCHEDULE_MEETING;
                $preSchedule   = false;
            }
        }

        return (object)[
            'topic'        => $request->title,
            'duration'     => $request->duration,
            'password'     => $request->password ?? null,
            'pre_schedule' => $preSchedule,
            'recurrence'   => $recurrence ?? null,
            'settings'     => [
                'jbh_time'         => $request->participant_join_before_host,
                'join_before_host' => $request->join_before_host,
                'waiting_room'     => empty($request->join_before_host),
            ],
            'start_time'   => $startDateTime,
            'timezone'     => $request->timezone,
            'type'         => $type
        ];
    }

    public static function setTzUTCViaDateAndMinutes($dateTime, $timezone = 'UTC'): bool|Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i:s', $dateTime, $timezone !== '' ? $timezone : null)
                     ->setTimezone('UTC');
    }

    /**
     * @Description
     *
     * @Author im.phien
     * @Date   Jun 23, 2022
     *
     * @param array $days
     *
     * @return array
     */
    public function handleDaysOfWeek(array $days): array
    {
        //assign index to each day
        $arrayDaysOfWeek
            = [
            ZoomMeetingSettingConstant::DAY_OF_WEEK_SUNDAY    => 'Sunday',
            ZoomMeetingSettingConstant::DAY_OF_WEEK_MONDAY    => 'Monday',
            ZoomMeetingSettingConstant::DAY_OF_WEEK_TUESDAY   => 'Tuesday',
            ZoomMeetingSettingConstant::DAY_OF_WEEK_WEDNESDAY => 'Wednesday',
            ZoomMeetingSettingConstant::DAY_OF_WEEK_THURSDAY  => 'Thursday',
            ZoomMeetingSettingConstant::DAY_OF_WEEK_FRIDAY    => 'Friday',
            ZoomMeetingSettingConstant::DAY_OF_WEEK_SATURDAY  => 'Saturday',
        ];

        $daysOfWeek = [];
        foreach ($days as $day) {
            $daysOfWeek[] = array_search($day, $arrayDaysOfWeek);
        }

        return $daysOfWeek;
    }

    /**
     * @Description
     *
     * @Author im.phien
     * @Date   Jun 24, 2022
     *
     * @param object $request
     *
     * @return array|void
     */
    private function _handleRequestRecurringMeeting(object $request)
    {
        $endDateTime = Carbon::parse($request->end . ' ' . $request->from_time)->format('Y-m-d\TH:i:s\Z');
        if ($request->repeat == CalendarRepeatTypeConstant::WEEKLY) {
            $daysRequest = [];
            foreach ($request->repeat_on as $itemRepeat) {
                $daysRequest[] = $itemRepeat['day_of_week'] ?? $itemRepeat;
            }
            $dayOfWeek = $this->handleDaysOfWeek($daysRequest);

            return [
                'end_date_time' => $endDateTime,
                'type'          => ZoomMeetingSettingConstant::RECURRING_TYPE_WEEKLY,
                'weekly_days'   => implode(",", $dayOfWeek),
            ];
        }
        if ($request->repeat == CalendarRepeatTypeConstant::DAILY) {
            return [
                'end_date_time' => $endDateTime,
                'type'          => ZoomMeetingSettingConstant::RECURRING_TYPE_DAILY,
            ];
        }
    }

    /**
     * @Description
     *
     * @Author im.phien
     * @Date   Jun 24, 2022
     *
     * @param int     $meetingId
     * @param Request $request
     *
     * @return object
     * @throws Throwable
     */
    public function generateLinkZoomMeetingViaMeetingId(Request $request, int $meetingId): object
    {
        $rule = [
            'title'                          => "required",
            'password'                       => "required|max:10",
            "zoom_meeting_type"              => "required|in:" . implode(',', ZoomMeetingTypeConstant::ALL),
            'duration'                       => "required|numeric|min:0",
            'notification_before'            => "numeric|min:0",
            'join_before_host'               => "required|boolean",
            'participant_join_before_host'   => "required",
            "start"                          => "required|date_format:Y-m-d|after_or_equal:now",
            "end"                            => "required|date_format:Y-m-d|after_or_equal:calendar.start",
            "from_time"                      => "required|date_format:H:i",
            "host_zoom_meeting.host.host_id" => "required|exists:zoom_hosts,host_id"
        ];
        $host = $request->host_zoom_meeting['host'];
        $this->doValidate($request, $rule);
        $meeting = (new ZoomMeetingSQL())::query()->where('id', $meetingId)->first();
        if (!$meeting) {
            throw new BadRequestException(
                ['message' => __("ZoomMeeting.delete_zoom_meeting")], new Exception()
            );
        }
        $host            = ZoomHostSQL::query()->where('host_id', $host['host_id'])->first();
        $token           = ZoomSettingSQL::query()->where('id', $host->zoom_setting_id)->first()?->token;
        $dataZoomMeeting = $this->_handleZoomMeetingRequest($request);
        try {
            DB::beginTransaction();
            $zoomMeetingUi               = $this->zoomMeetingHelper->createZoomMeeting($host['host_id'],
                                                                                       $dataZoomMeeting,
                                                                                       $token);
            $meeting->link_zoom          = $zoomMeetingUi?->join_url;
            $meeting->zoom_meeting_ui_id = $zoomMeetingUi?->id;
            $meeting->pmi                = $zoomMeetingUi?->pmi;
            $meeting->save();
            DB::commit();

            return $meeting;
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @Author apple
     * @Date   Jul 04, 2022
     *
     * @param int $id
     *
     * @return EBuilder|ZoomMeetingSQL|null
     */
    public function getZoomMeetingAndCountStudent(int $id): EBuilder|ZoomMeetingSQL|null
    {
        return ZoomMeetingSQL::query()
                             ->join('zoom_participants', 'zoom_participants.zoom_meeting_id',
                                    'zoom_meetings.id')
                             ->with('hostZoomMeeting.host')
                             ->withCount('participants')
                             ->where('zoom_meetings.id', $id)
                             ->first();
    }

    /**
     * @Description
     *
     * @Author im.phien
     * @Date   Jul 04, 2022
     *
     * @param Request $request
     *
     * @return bool|null
     */
    public function hookDataZoomMeeting(Request $request): ?bool
    {
        $event = $request->event;

        if ($event != ZoomMeetingEventConstant::JOIN_MEETING_ROOM && $event != ZoomMeetingEventConstant::LEFT_MEETING_ROOM) {
            return null;
        }

        $zoomMeeting = $this->getViaZoomMeetingUiId($request->payload['object']['id']);

        if (!$zoomMeeting) {
            return null;
        }
        Log::info('Data zoom meeting', $request->toArray());

        $emailParticipant    = $request->payload['object']['participant']['email'];
        $userNameParticipant = $request->payload['object']['participant']['user_name'];
        $idParticipant       = $request->payload['object']['participant']['id'];
        $hostMeeting         = (new ZoomHostSQL())::query()
                                                  ->join('zoom_participants', 'zoom_participants.user_uuid', '=',
                                                         'zoom_hosts.uuid')
                                                  ->where('zoom_participants.zoom_meeting_id', $zoomMeeting->id)
                                                  ->where('zoom_participants.user_join_meeting', '=',
                                                          UserJoinZoomMeetingConstant::HOST)
                                                  ->where('zoom_hosts.host_id', $idParticipant)
                                                  ->first();

        if ($emailParticipant) {
            $user = (new ZoomParticipantService())->buildQueryParticipantViaZoomMeetingId($zoomMeeting->id)
                                                  ->where('email', $emailParticipant)->first();
        } else {
            $user = (new ZoomParticipantService())->buildQueryParticipantViaZoomMeetingId($zoomMeeting->id)
                                                  ->where('full_name', 'LIKE', '%' . $userNameParticipant . '%')
                                                  ->first();
        }

        if (!$user && !$hostMeeting)
            return null;

        $startTimeMeeting = Carbon::parse($request->payload['object']['start_time'])->format('Y-m-d');
        $attendanceLog    = (new AttendanceLogSQL())::query()
                                                    ->when($user, function ($q) use ($user) {
                                                        $q->where('user_uuid', $user->uuid);
                                                    })
                                                    ->when($hostMeeting, function ($q) use ($hostMeeting) {
                                                        $q->where('user_uuid', $hostMeeting->uuid);
                                                    })
                                                    ->where('zoom_meeting_id', $zoomMeeting->id)
                                                    ->whereDate('date', $startTimeMeeting)
                                                    ->first();

        if ($event == ZoomMeetingEventConstant::JOIN_MEETING_ROOM) {
            $joinTime                                = Carbon::parse($request->payload['object']['participant']['join_time'])
                                                             ->format('Y-m-d H:i:s');
            $attendanceLog->join_time                = $joinTime ?? null;
            $attendanceLog->participant_display_name = $request->payload['object']['participant']['user_name'];
            $attendanceLog->email                    = $request->payload['object']['participant']['email'];

        }

        if ($event == ZoomMeetingEventConstant::LEFT_MEETING_ROOM) {
            $leaveTime = Carbon::parse($request->payload['object']['participant']['leave_time'])->format('Y-m-d H:i:s');

            //get the difference between 2 times
            $timeStart = new Carbon($attendanceLog->join_time);
            $endTime   = new Carbon($leaveTime);
            $duration  = $endTime->diffInMinutes($timeStart);

            $attendanceLog->leave_time = $leaveTime ?? null;
            $attendanceLog->duration   = $duration ?? null;
            $attendanceLog->status     = StatusAttendanceLogConstant::PRESENT;
        }

        $attendanceLog->save();

        Log::info('Attendance log update', [$attendanceLog]);

        return true;
    }

    /**
     * @Description
     *
     * @Author im.phien
     * @Date   Jul 04, 2022
     *
     * @param int $idZoomMeetingUi
     *
     * @return Model|EBuilder|ZoomMeetingSQL|null
     */
    public function getViaZoomMeetingUiId(int $idZoomMeetingUi): Model|EBuilder|ZoomMeetingSQL|null
    {
        return (new ZoomMeetingSQL())::query()->where('zoom_meeting_ui_id', $idZoomMeetingUi)
                                     ->first();
    }

    /**
     * @Author apple
     * @Date   Jul 04, 2022
     *
     * @param int|string $id
     *
     * @return Model
     */
    public function get(int|string $id): Model
    {
        // validate role_id
        if (!$this->hasAnyRoleWithUser(RoleServiceProvider::$currentRole?->id)) {
            throw new BadRequestException(
                ['message' => __("role.validate_role_id")], new Exception()
            );
        }

        try {
            if ($this->queryHelper->relations)
                $this->model = $this->model->with($this->queryHelper->relations);

            return $this->model->when(RoleServiceProvider::$currentRole?->name == RoleConstant::TEACHER,
                function ($q) {
                    $q->where('created_by', BaseService::currentUser()?->id);
                })->findOrFail($id);

        } catch (ModelNotFoundException $e) {
            throw new NotFoundException(
                ['message' => __("not-exist", ['attribute' => __('entity')]) . ": $id"],
                $e
            );
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @throws Throwable
     */
    public function assignStudentsToVcrCViaClassIdAndUserIds(int $classId, array $userIds): bool
    {
        $users                = UserSQL::query()->whereIn('id', $userIds)->select('id', 'uuid')->get();
        $dataZoomParticipants = [];
        $dataAttendances      = [];

        $calendars = (new CalendarService())->getCalendarVcrByClassId($classId);

        if (!$calendars)
            return true;
        foreach ($users as $user) {
            $zoomMeetingId = "";
            foreach ($calendars as $calendar) {
                $dataAttendances[] = [
                    'zoom_meeting_id' => $calendar['zoom_meeting_id'],
                    'user_uuid'       => $user->uuid,
                    'calendar_id'     => $calendar->id,
                    'class_id'        => $classId,
                    'start'           => Carbon::parse($calendar->start->toDateTimeString())->format('Y-m-d'),
                    'end'             => Carbon::parse($calendar->end->toDateTimeString())->format('Y-m-d'),
                    'user_id'         => $user->id,
                    'status'          => null,
                    'group'           => null,
                    'date'            => Carbon::parse($calendar->start->toDateTimeString())->format('Y-m-d H:i:s'),
                    'created_by'      => BaseService::currentUser()->id
                ];

                if ($zoomMeetingId != $calendar['zoom_meeting_id'])
                    $dataZoomParticipants[] = [
                        'zoom_meeting_id'    => $calendar['zoom_meeting_id'],
                        'user_uuid'          => $user->uuid,
                        'user_join_meeting'  => UserJoinZoomMeetingConstant::STUDENT,
                        'type_guest'         => $this->guestObjectInviteToZoom[0],
                        'class_id'           => $classId,
                        'student_attendance' => $calendar->zoomMeeting->student_attendance
                    ];
                $zoomMeetingId = $calendar['zoom_meeting_id'];
            }
        }

        ZoomParticipantSQL::query()->insert($dataZoomParticipants);
        AttendanceSQL::query()->insert($dataAttendances);

        return true;

    }

    /**
     * @throws Throwable
     */
    public function unAssignStudentsToVcrCViaClassIdAndUserIds(int $classId, array $userIds): bool
    {
        $userUuids = UserSQL::query()->whereIn('id', $userIds)->pluck('uuid')->toArray();

        $calendars = (new CalendarService())->getCalendarVcrByClassId($classId);

        if (!$calendars)
            return true;
        foreach ($userUuids as $userUuid) {
            foreach ($calendars as $calendar) {
                AttendanceSQL::query()->where('calendar_id', $calendar->_id)
                             ->where('user_uuid', $userUuid)
                             ->delete();
            }
        }

        ZoomParticipantSQL::query()->where('class_id', $classId)
                          ->whereIn('user_uuid', $userUuids)
                          ->delete();

        return true;
    }

    /**
     * @throws Throwable
     */
    public function assignTeachersToVcrCViaClassIdAndUserIds(int $classId, array $userIds): bool
    {
        $userUuids    = UserSQL::query()->whereIn('id', $userIds)->pluck('uuid')->toArray();
        $teacherUuids = ClassAssignmentSQL::query()
                                          ->join('users', 'users.id', '=', 'class_assignments.user_id')
                                          ->whereIn('assignment', ClassAssignmentConstant::TEACHER)
                                          ->pluck('users.uuid')->toArray();

        $calendars = (new CalendarService())->getCalendarVcrByClassId($classId);

        $dataZoomParticipants = [];

        foreach ($userUuids as $userUuid) {
            $zoomMeetingId = "";
            foreach ($calendars as $calendar) {
                if ($zoomMeetingId != $calendar['zoom_meeting_id'])
                    $dataZoomParticipants[] = [
                        'zoom_meeting_id'    => $calendar['zoom_meeting_id'],
                        'user_uuid'          => $userUuid,
                        'user_join_meeting'  => UserJoinZoomMeetingConstant::STUDENT,
                        'type_guest'         => $this->guestObjectInviteToZoom[0],
                        'class_id'           => $classId,
                        'student_attendance' => $calendar->zoomMeeting->student_attendance
                    ];
                $zoomMeetingId = $calendar['zoom_meeting_id'];
            }
        }

        ZoomParticipantSQL::query()->where('class_id', $classId)
                          ->whereIn('user_uuid', $teacherUuids)
                          ->delete();
        ZoomParticipantSQL::query()->insert($dataZoomParticipants);

        return true;

    }

    public function validateUpdateRecurringWithDailyMeeting(object $request)
    {
        $this->doValidate($request, [
            "calendar.end"       => "required|date_format:Y-m-d|after_or_equal:" . Carbon::now()->format('Y-m-d'),
            "calendar.from_time" => "required|date_format:H:i",
        ],
                          ['after_or_equal' => __('ZoomMeeting.zoom_meeting_start_date'), 'after' => __('ZoomMeeting.zoom_meeting_end_date')]);

    }

    public function validateUpdateRecurringWithWeeklyMeeting(object $request)
    {
        $this->doValidate($request, [
            'calendar.end'                     => 'required|date_format:Y-m-d|after:calendar.start' . Carbon::now()
                                                                                                            ->format('Y-m-d'),
            'calendar.from_time'               => 'required|date_format:H:i',
            'calendar.repeat_on'               => 'nullable|array',
            'calendar.repeat_on.*.day_of_week' => 'required|in:' . implode(',', $this->dayOfWeek),
        ],
                          ['after_or_equal' => __('ZoomMeeting.zoom_meeting_start_date'), 'after' => __('ZoomMeeting.zoom_meeting_end_date')]);

    }
}
