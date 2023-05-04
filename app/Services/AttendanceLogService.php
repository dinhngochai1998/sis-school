<?php
/**
 * @Author apple
 * @Date   Jun 27, 2022
 */

namespace App\Services;

use Carbon\Carbon;
use DB;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Request;
use MongoDB\BSON\UTCDateTime;
use YaangVu\Constant\AttendanceConstant;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Constant\StatusAttendanceLogConstant;
use YaangVu\Constant\UserJoinZoomMeetingConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\ForbiddenException;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Helpers\QueryHelper;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Constants\CalendarTypeConstant;
use YaangVu\SisModel\App\Models\impl\AttendanceLogSQL;
use YaangVu\SisModel\App\Models\impl\AttendanceSQL;
use YaangVu\SisModel\App\Models\impl\CalendarNoSQL;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class AttendanceLogService extends BaseService
{
    use RoleAndPermissionTrait;

    protected array $statusAttendanceLog = StatusAttendanceLogConstant::ALL;

    function createModel(): void
    {
        $this->model = new AttendanceLogSQL();
    }

    /**
     * @Author apple
     * @Date   Jun 27, 2022
     *
     * @param object $request
     * @param int    $zoomMeetingId
     *
     * @return LengthAwarePaginator
     */

    public function getAttendanceLogByZoomMeetingId(object $request, int $zoomMeetingId): LengthAwarePaginator
    {
        $isStudent = $this->hasAnyRole(RoleConstant::STUDENT);
        if (!($this->hasPermission(PermissionConstant::zoomMeeting(PermissionActionConstant::REPORT))))
            throw new ForbiddenException(__('role.forbidden'), new Exception());

        return $this->queryGetAttendanceLogViaZoomMeetingId($request, $zoomMeetingId)
                    ->whereIn('user_uuid', function ($q) use ($isStudent) {
                        $q->select('user_uuid')
                          ->from('zoom_participants')
                          ->when($isStudent, function ($query) {
                              $query->where('user_uuid', BaseService::currentUser()->uuid);
                          })
                          ->where('user_join_meeting', UserJoinZoomMeetingConstant::STUDENT)
                          ->pluck('user_uuid');
                    })
                    ->paginate(QueryHelper::limit());
    }

    public function preUpdate(int|string $id, object $request)
    {
        $isTeacher = $this->hasAnyRole(RoleConstant::TEACHER);
        if (!($this->hasPermission(PermissionConstant::zoomMeeting(PermissionActionConstant::UPDATE_REPORT)) || $isTeacher))
            throw new ForbiddenException(__('role.forbidden'), new Exception());

        $this->doValidate($request, [
            'status'     => 'required|in:' . implode(',', $this->statusAttendanceLog),
            'join_time'  => 'required|date_format:Y-m-d H:i:s',
            'leave_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:join_time'
        ]);

        $this->_handleRequestParam($request);

        parent::preUpdate($id, $request);
    }

    public function _handleRequestParam(object $request): object
    {
        $joinTime  = new Carbon($request->join_time);
        $leaveTime = new Carbon($request->leave_time);
        $duration  = $joinTime->diffInMinutes($leaveTime);

        if ($request instanceof Request) {
            $request->merge(
                [
                    'duration' => $duration
                ]
            );
        } else {
            $request->duration = $duration;
        }

        return $request;
    }

    public function getReportStatistics(object $request, int $zoomMeetingId): array
    {
        $isTeacher = $this->hasAnyRole(RoleConstant::TEACHER);
        if (!($this->hasPermission(PermissionConstant::zoomMeeting(PermissionActionConstant::REPORT)) || $isTeacher))
            throw new ForbiddenException(__('role.forbidden'), new Exception());

        $zoomMeeting = (new ZoomMeetingService())->getZoomMeetingAndCountStudent($zoomMeetingId);
        $date        = $request->date ?? null;
        $timezone    = $request->timezone ?? null;

        $calendar = (new CalendarService())->getViaZoomMeetingIdAndDateAndTimezone($zoomMeetingId, $date, $timezone);

        if (!$zoomMeeting)
            throw new BadRequestException(
                ['message' => __("validation.not-exist", ['attribute' => __($zoomMeetingId)])], new Exception()
            );

        $attendanceLogs      = $this->queryGetAttendanceLogViaZoomMeetingId($request, $zoomMeetingId)->get();
        $countAttendanceLogs = $attendanceLogs->count();
        //$invitee              = $zoomMeeting->participants_count ?? null;

        if (empty($attendanceLogs)) {
            return [];
        }

        $totalDuration        = $statusPresent = 0;
        $statusAbsence        = 0;
        $statusTardy          = 0;
        $hostJoinTime         = $hostDuration = "";
        $emailHostZoomMeeting = $zoomMeeting->hostZoomMeeting['host']['email'] ?? null;
        foreach ($attendanceLogs as $attendanceLog) {
            $totalDuration += $attendanceLog['duration'];
            if ($attendanceLog['email'] != $emailHostZoomMeeting && $attendanceLog['status'] == StatusAttendanceLogConstant::PRESENT)
                $statusPresent += 1;

            if ($attendanceLog['email'] != $emailHostZoomMeeting && in_array($attendanceLog['status'],
                                                                             StatusAttendanceLogConstant::ALL_ABSENCE))
                $statusAbsence += 1;

            if ($attendanceLog['email'] != $emailHostZoomMeeting && in_array($attendanceLog['status'],
                                                                             StatusAttendanceLogConstant::ALL_TARDY))
                $statusTardy += 1;

            if (!empty($emailHostZoomMeeting) && $attendanceLog['email'] == $emailHostZoomMeeting) {
                $hostJoinTime = $attendanceLog['join_time'];
                $hostDuration = $attendanceLog['duration'];
            }

        }

        if ($totalDuration == 0 || $statusPresent == 0)
            $averageDuration = 0;
        else
            $averageDuration = $totalDuration / $statusPresent;

        $present = $countAttendanceLogs ? round(($statusPresent / $countAttendanceLogs) * 100, 2) : 0;
        $absence = $countAttendanceLogs ? round(($statusAbsence / $countAttendanceLogs) * 100, 2) : 0;
        $tardy   = $countAttendanceLogs ? round(($statusTardy / $countAttendanceLogs) * 100, 2) : 0;

        return [
            'data'  => [
                'invitee'              => $countAttendanceLogs,
                'present'              => $statusPresent,
                'absence'              => $statusAbsence,
                'tardy'                => $statusTardy,
                'average_duration'     => $averageDuration,
                'host_join_time'       => $hostJoinTime,
                'host_duration'        => $hostDuration,
                'timezone'             => $zoomMeeting->timezone,
                'zoom_meeting_comment' => $calendar->zoom_meeting_comment ?? null
            ],
            'chart' => [
                'present' => $present,
                'absence' => $absence,
                'tardy'   => $tardy
            ]
        ];
    }

    /**
     * @Author apple
     * @Date   Jul 01, 2022
     *
     * @param object $request
     * @param int    $zoomMeetingId
     *
     * @return LengthAwarePaginator
     */
    public function getDateByZoomMeetingId(object $request, int $zoomMeetingId): LengthAwarePaginator
    {
        $calendarIds = CalendarNoSQL::query()
                                    ->where('zoom_meeting_id', $zoomMeetingId)
                                    ->where('status', CalendarTypeConstant::FUTURE)
                                    ->where('start', '<=', Carbon::now())
                                    ->pluck('_id')
                                    ->toArray();

        return AttendanceSQL::query()
                            ->join('zoom_meetings', 'attendances.zoom_meeting_id', '=', 'zoom_meetings.id')
                            ->whereIn('calendar_id', $calendarIds)
                            ->groupBy('date', 'zoom_meetings.id')
                            ->select('zoom_meetings.*', 'date')
                            ->paginate(QueryHelper::limit());

    }

    /**
     * @Author apple
     * @Date   Jul 01, 2022
     *
     * @param object $request
     * @param int    $zoomMeetingId
     *
     * @return bool
     */
    public function deleteAttendanceLogByZoomMeetingId(object $request, int $zoomMeetingId): bool
    {
        $calendars = CalendarNoSQL::query()->where('zoom_meeting_id', $zoomMeetingId)
                                  ->get();

        $currentTime = Carbon::now()->format('Y-m-d H:i:s');
        $calendarIds = [];
        foreach ($calendars as $calendar) {
            $startDateTime = Carbon::parse($calendar->start)->toDateTimeString();
            if ($currentTime < $startDateTime)
                $calendarIds[] = $calendar->_id;
        }

        AttendanceSQL::query()->whereIn('calendar_id', $calendarIds)->delete();

        return true;
    }

    public function queryGetAttendanceLogViaZoomMeetingId(object $request,
                                                          int    $zoomMeetingId): AttendanceSQL|\Illuminate\Database\Eloquent\Builder
    {
        $date = $request->date ?? null;

        return AttendanceSQL::query()
                            ->with(['userNoSql', 'class'])
                            ->when($date, function ($q) use ($date) {
                                $dateTime = Carbon::parse($date)
                                                  ->format('Y-m-d H:i:s');
                                $q->where('date', $dateTime);
                            })
                            ->where('zoom_meeting_id', $zoomMeetingId)
                            ->orderBy('id', 'DESC');
    }

    /**
     * @throws \Throwable
     */
    public function updateAttendanceLogsViaZoomMeetingId(object $request, int $zoomMeetingId): object|bool|null
    {
        if (!$this->hasPermission(PermissionConstant::zoomMeeting(PermissionActionConstant::EDIT))) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }

        $rules = [
            'date'                               => 'sometimes|date_format:Y-m-d H:i',
            'data_attendance_logs'               => 'sometimes|array',
            'data_attendance_logs.*.id'          => 'sometimes|exists:attendances,id',
            'data_attendance_logs.*.calendar_id' => 'sometimes|exists:mongodb.calendars,_id'
        ];
        $this->doValidate($request, $rules);
        $date     = $request->date ?? null;
        $timezone = $request->timezone ?? null;

        $calendar = (new CalendarService())->getViaZoomMeetingIdAndDateAndTimezone($zoomMeetingId, $date, $timezone);
        CalendarNoSQL::query()->where('group', $calendar->group)
                     ->update(['zoom_meeting_comment' => $request->zoom_meeting_comment]);


        if (empty($request->data_attendance_logs)) {
            return true;
        }
        $attendances   = [];
        $attendanceIds = [];

        foreach ($request->data_attendance_logs as $keyAttendanceLog => $dataAttendanceLog) {
            $attendance      = AttendanceSQL::query()
                                            ->join('users', 'users.id', '=', 'attendances.user_id')
                                            ->join('zoom_meetings', 'zoom_meetings.id', '=',
                                                   'attendances.zoom_meeting_id')
                                            ->where('attendances.id', $dataAttendanceLog['id'])
                                            ->select('attendances.*', 'users.id as user_id',
                                                     'zoom_meetings.timezone as timezone')
                                            ->first();
            $attendanceIds[] = $attendance->id;

            $dateString = Carbon::parse($attendance->date)->format("Y-m-d");
            $fromTime   = Carbon::parse($attendance->date)->format("H:i");

            $startDateConvert = new UTCDateTime((new CalendarService())->setTzUTCViaDateAndMinutes($dateString,
                                                                                                   $fromTime,
                                                                                                   $attendanceLog->timezone ?? null));
            $date             = Carbon::parse($startDateConvert->toDateTime())->format('Y-m-d');

            $attendances[] = [
                'class_id'        => $attendance->class_id,
                'calendar_id'     => $dataAttendanceLog['calendar_id'],
                'user_uuid'       => $attendance->user_uuid,
                'user_id'         => $attendance->user_id,
                'description'     => $dataAttendanceLog['description'] ?? $attendance->description,
                'created_by'      => $attendance->created_by,
                'status'          => $dataAttendanceLog['status'] ?? $attendance->status,
                'group'           => empty($dataAttendanceLog['status']) ? null : AttendanceConstant::GROUP_REVERSE[$dataAttendanceLog['status']],
                'start'           => $date,
                'end'             => $date,
                'join_time'       => $dataAttendanceLog['join_time'],
                'leave_time'      => $dataAttendanceLog['leave_time'],
                'zoom_meeting_id' => $attendance->zoom_meeting_id,
                'date'            => $attendance->date
            ];

            $joinTime  = $attendances[$keyAttendanceLog]['join_time'];
            $leaveTime = $attendances[$keyAttendanceLog]['leave_time'];

            if (!empty($joinTime) && !empty($leaveTime) && ($joinTime > $leaveTime))
                throw new BadRequestException(
                    ['message' => 'The data ' . $keyAttendanceLog . ' join_time and leave_time invalid'],
                    new Exception());
        }

        DB::beginTransaction();
        try {
            AttendanceSQL::query()->whereIn('id', $attendanceIds)
                         ->delete();

            AttendanceSQL::query()->insert($attendances);

            DB::commit();

            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    public function getAttendanceLogsViaGroupCalendar(string $group): LengthAwarePaginator
    {
        $isStudent = $this->hasAnyRole(RoleConstant::STUDENT);
        if (!($this->hasPermission(PermissionConstant::zoomMeeting(PermissionActionConstant::REPORT))))
            throw new ForbiddenException(__('role.forbidden'), new Exception());

        $calendarIds = CalendarNoSQL::query()->where('group', $group)
                                    ->pluck('_id')->toArray();

        return AttendanceSQL::query()
                            ->with(['userNoSql', 'class'])
                            ->whereIn('calendar_id', $calendarIds)
                            ->when($isStudent, function ($q) {
                                $q->where('user_uuid', BaseService::currentUser()->uuid);
                            })
                            ->paginate(QueryHelper::limit());
    }
}
