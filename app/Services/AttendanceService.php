<?php

namespace App\Services;

use App\Helpers\ElasticsearchHelper;
use Carbon\Carbon;
use DB;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use MongoDB\BSON\UTCDateTime;
use Throwable;
use YaangVu\Constant\AttendanceConstant;
use YaangVu\Constant\ClassAssignmentConstant;
use YaangVu\Constant\GuestObjectInviteToZoomConstant;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Constant\StatusConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\ForbiddenException;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\AttendanceSQL;
use YaangVu\SisModel\App\Models\impl\CalendarNoSQL;
use YaangVu\SisModel\App\Models\impl\ClassAssignmentSQL;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\impl\CommentNoSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Models\impl\UserParentSQL;
use YaangVu\SisModel\App\Models\impl\UserSQL;
use YaangVu\SisModel\App\Providers\RoleServiceProvider;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class AttendanceService extends BaseService
{
    public CalendarService $calendarService;
    use RoleAndPermissionTrait, ElasticsearchHelper;

    public function __construct()
    {
        $this->calendarService = new CalendarService();
        parent::__construct();
    }

    public function createModel(): void
    {
        $this->model = new AttendanceSQL();
    }

    public function getAllViaCalendarId(string $calendarId): Model|Builder|null
    {
        $calendar         = $this->calendarService->get($calendarId);
        $classId          = $calendar?->class_id;
        $isTeacherInClass = (new ClassAssignmentService)->getViaClassIdAndAssignment(
            $classId,
            [ClassAssignmentConstant::PRIMARY_TEACHER, ClassAssignmentConstant::SECONDARY_TEACHER],
            self::currentUser()->id
        );
        if (!$this->isGod() &&
            !($this->hasPermission(PermissionConstant::attendance(PermissionActionConstant::VIEW)) && $isTeacherInClass))
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());

        $class              = ClassSQL::whereId($classId)
                                      ->with([
                                                 'students.users.userNoSql',
                                                 'teachers' => function ($q) {
                                                     $q->where('assignment', ClassAssignmentConstant::PRIMARY_TEACHER);
                                                 },
                                                 'teachers.users.userNoSql'
                                             ])
                                      ->first();
        $attendances        = self::getViaClassIdAndCalendarId($classId, $calendar->id)->toArray();
        $attendanceUserUuid = array_column($attendances, 'user_uuid');
        $countStatus        = [];
        foreach ($class->students ?? [] as $student) {
            if (count($attendances) == 0)
                break;
            // find student has been attendance
            $isStudentHasAttendance = array_search($student?->users?->uuid, $attendanceUserUuid);

            if (!is_bool($isStudentHasAttendance))
                $attendanceStatus = $attendances[$isStudentHasAttendance]['status'];
            else
                $attendanceStatus = AttendanceConstant::PRESENT;
            // if student is attendance then add attendance status else add attendance status default
            $student->users->attendance_status      = $attendanceStatus;
            $student->users->attendance_description = $attendances[$isStudentHasAttendance]['description'];;
            // count students by status
            $countStatus[$attendanceStatus] = $countStatus[$attendanceStatus] ?? null ?
                    $countStatus[$attendanceStatus] + 1 : 1;
        }

        $class->calendar_start_date = $calendar->start ?? null;
        $class->calendar_end_date   = $calendar->end ?? null;
        $class->{'timezone'}        = $calendar->timezone ?? null;
        $class->cout_present        = $countStatus[AttendanceConstant::PRESENT] ?? 0;
        $class->cout_unex_tardy     = $countStatus[AttendanceConstant::UNEX_TARDY] ?? 0;
        $class->cout_unex_absence   = $countStatus[AttendanceConstant::UNEX_ABSENCE] ?? 0;
        $class->cout_ex_tardy       = $countStatus[AttendanceConstant::EX_TARDY] ?? 0;
        $class->cout_ex_absence     = $countStatus[AttendanceConstant::EX_ABSENCE] ?? 0;
        $class->comment             = CommentNoSQL::whereClassId($classId)
                                                  ->where('calendar_id', $calendar->id)
                                                  ->first();

        return $class;
    }

    public static function getViaClassIdAndCalendarId(string|int $classId, string|int $calendarId): Collection|array
    {
        return AttendanceSQL::whereClassId($classId)
                            ->whereCalendarId($calendarId)
                            ->get();
    }

    /**
     * insert batch attendances
     *
     * @param object $request
     *
     * @return array
     * @throws Throwable
     */
    public function insertBatch(object $request): array
    {
        $classId          = (new ClassService())->get($request->class_id ?? null)->id;
        $isTeacherInClass = (new ClassAssignmentService)->getViaClassIdAndAssignment(
            $classId,
            [ClassAssignmentConstant::PRIMARY_TEACHER, ClassAssignmentConstant::SECONDARY_TEACHER],
            self::currentUser()->id
        );

        if (!$this->isGod() &&
            !($this->hasPermission(PermissionConstant::attendance(PermissionActionConstant::EDIT)) && $isTeacherInClass))
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());

        $rules = [
            'calendar_id'     => 'required|exists:mongodb.calendars,_id,deleted_at,NULL',
            'class_id'        => 'required|exists:classes,id,deleted_at,NULL',
            'users'           => 'required|array',
            'users.*.user_id' => 'required|exists:mongodb.users,_id,deleted_at,NULL',
            'users.*.status'  => 'required|in:' . implode(',', AttendanceConstant::ALL)
        ];
        $this->doValidate($request, $rules);
        $calendar = (new CalendarService())->get($request->calendar_id);
        foreach ($request->users as $user) {
            $userNoSql     = (new UserService())->get($user['user_id']);
            $attendances[] = [
                'calendar_id' => $calendar->id,
                'class_id'    => $request->class_id,
                'user_uuid'   => $userNoSql->uuid ?? null,
                'user_id'     => $userNoSql?->userSql->id ?? null,
                'status'      => $user['status'],
                'description' => $user['description'] ?? null,
                'created_by'  => self::currentUser()?->id ?? null,
                'group'       => AttendanceConstant::GROUP_REVERSE[$user['status']],
                'start'       => $calendar->start ?? null,
                'end'         => $calendar->end ?? null,
            ];
        }
        DB::beginTransaction();
        try {
            AttendanceSQL::whereClassId($request->class_id)
                         ->whereCalendarId($request->calendar_id)
                         ->delete();
            $this->model->insert($attendances ?? []);

            CommentNoSQL::query()->updateOrInsert(
                [
                    'class_id'    => $classId,
                    'calendar_id' => $calendar->id
                ],
                [
                    'class_id'    => $classId,
                    'calendar_id' => $calendar->id,
                    'comment'     => $request->comment ?? null
                ]
            );
            DB::commit();

            return $attendances ?? [];
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    function getViaUserIdAndClassId(string $userId, int $classId): array|Collection
    {
        $userNoSql = (new UserService())->get($userId);

        return $this->model->with('calendar')
                           ->where('user_id', $userNoSql?->userSql?->id ?? null)
                           ->where('class_id', $classId)
                           ->orderBy('attendances.end', 'DESC')
                           ->get();
    }

    public function getAttendancePercentStatus(Request $request): array
    {
        $attendances  = $this->getAttendanceReport($request);
        $statusDetail = [];
        $numberStatus = [];
        foreach ($attendances as $attendance) {
            $attendanceStatus                = $attendance['status'];
            $numberStatus[$attendanceStatus] = $numberStatus[$attendanceStatus] ?? null ?
                    $numberStatus[$attendanceStatus] + 1 : 1;
        }
        $percent = 'percent';
        $number  = 'number';
        foreach (AttendanceConstant::ALL as $value) {
            if (count($attendances) != 0)
                $statusDetail[$value][$percent] = round(($numberStatus[$value] ?? 0) * 100 / count($attendances), 2);
            else
                $statusDetail[$value][$percent] = 0;
            $statusDetail[$value][$number] = $numberStatus[$value] ?? 0;
        }

        return $statusDetail;
    }

    public function getAttendanceReport(Request $request): Collection|array
    {
        $this->checkPermissionAttendanceReport();
        $rules = [
            'class_id'     => 'exists:classes,id,school_id,' . SchoolServiceProvider::$currentSchool->id,
            'user_id'      => 'exists:mongodb.users,_id',
            'class_status' => Rule::in(StatusConstant::ALL)
        ];
        $this->doValidate($request, $rules);

        $this->queryHelper->removeParam('start_date')
                          ->removeParam('end_date')
                          ->removeParam('status')
                          ->removeParam('class_id')
                          ->removeParam('class_status')
                          ->removeParam('user_id')
                          ->removeParam('children_id');
        $currentDay  = Carbon::today();
        $startDate   = request('start_date');
        $endDate     = request('end_date');
        $classId     = request('class_id');
        $userId      = request('user_id');
        $classStatus = request('class_status');
        $childrenId  = request('children_id');
        $currentUser = BaseService::currentUser();

        $user        = (new UserNoSQL())->where('_id', $userId)->first();
        $calendarIds = (new CalendarNoSQL())::query()
                                            ->when($startDate, function ($q) use ($startDate, $endDate) {
                                                $dateTimeStart    = Carbon::createFromFormat('Y-m-d H:i:s', $startDate,
                                                                                             'UTC')
                                                                          ->setTimezone('UTC');
                                                $dateTimeEnd      = Carbon::createFromFormat('Y-m-d H:i:s', $endDate,
                                                                                             'UTC')
                                                                          ->setTimezone('UTC');
                                                $dateStartRequest = Carbon::parse($startDate)->format('Y-m-d');
                                                $dateEndRequest   = Carbon::parse($endDate)->format('Y-m-d');
                                                $q->where(function ($query) use ($dateTimeStart, $dateTimeEnd) {
                                                    $query->where('start', '>=', $dateTimeStart)
                                                          ->where('end', '<=', $dateTimeEnd);
                                                })
                                                  ->orWhere(function ($query) use ($dateStartRequest, $dateEndRequest) {
                                                      $query->where('start', '>=', $dateStartRequest)
                                                            ->where('end', '<=', $dateEndRequest);
                                                  });
                                            })
                                            ->when($classId, function ($query) use ($classId) {
                                                $query->where('class_id', '=', (int)$classId);
                                            })->pluck('_id')->toArray();
        $currentRole = RoleServiceProvider::$currentRole->name;

        return $this->queryHelper->buildQuery(new AttendanceSQL())
                                 ->join('classes', 'classes.id', '=', 'attendances.class_id')
                                 ->join('users', 'users.id', '=', 'attendances.user_id')
                                 ->when($user, function ($query) use ($user) {
                                     $query->where('users.uuid', '=', $user->uuid);
                                 })
                                 ->whereIn('attendances.calendar_id', $calendarIds)
                                 ->where('classes.status', $classStatus)
            //->where('end', '<=', $currentDay)
                                 ->when(in_array($currentRole, [RoleConstant::FAMILY, RoleConstant::STUDENT]),
                function ($q) use ($currentRole, $currentUser, $childrenId) {
                    if ($currentRole == RoleConstant::STUDENT) {
                        $q->where('users.uuid', '=', $currentUser->uuid);
                    }
                    if ($currentRole == RoleConstant::FAMILY) {
                        $children = UserNoSQL::query()->where('_id', $childrenId)->first();
                        $q->where('users.uuid', '=', $children->uuid);
                    }
                })
                                 ->select('attendances.*')->get();
    }

    public function checkPermissionAttendanceReport()
    {
        $isDynamic = $this->hasPermission(PermissionConstant::attendanceReport(PermissionActionConstant::VIEW));
        if (!$isDynamic)
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());
    }

    public function getAttendancePercentStudent(Request $request): array
    {
        //$classId             = request('class_id');
        $attendances         = $this->getAttendanceReport($request);
        $numberAttendance    = 0;
        $numberStudentStatus = [];
        foreach ($attendances as $attendance) {
            if ($attendance['status'])
                $numberAttendance += 1;
            $attendanceStatus                       = $attendance['status'];
            $numberStudentStatus[$attendanceStatus] = $numberStudentStatus[$attendanceStatus] ?? null ?
                    $numberStudentStatus[$attendanceStatus] + 1 : 1;
        }
        // $assignmentStudent = (new ClassAssignmentSQL())
        //     ->when($classId, function ($query) use ($classId) {
        //         $query->where('class_id', $classId);
        //     })
        //     ->where('assignment', ClassAssignmentConstant::STUDENT)
        //     ->count();
        $studentDetail  = [];
        $percentStudent = 'percent_student';
        $numberStatus   = 'number_student';
        foreach (AttendanceConstant::ALL as $value) {
            $numberAttendance ? $studentDetail[$value][$percentStudent]
                = round(($numberStudentStatus[$value] ?? 0) * 100 / $numberAttendance, 2)
                : $studentDetail[$value][$percentStudent] = 0;
            $studentDetail[$value][$numberStatus] = $numberStudentStatus[$value] ?? 0;
        }
        $studentDetail['sum_number_student'] = $numberAttendance;

        return $studentDetail;
    }

    /**
     * @Author Edogawa Conan
     * @Date   Apr 26, 2022
     *
     * @param string|int $userId
     *
     * @return array|Collection
     */
    public function getViaStudentId(string|int $userId): array|Collection
    {
        if (!$this->isGod() && !$this->hasPermissionViaRoleId(PermissionConstant::student(PermissionActionConstant::VIEW),
                                                              RoleServiceProvider::$currentRole->id)) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }
        $userNoSql = (new UserService())->get($userId);
        $userSqlId = $userNoSql->userSql->id;

        $classes = ClassSQL::query()
                           ->with([
                                      'attendances' => function ($q) use ($userSqlId) {
                                          $q->where('attendances.user_id', $userSqlId);
                                          $q->orderBy('attendances.start', 'DESC');
                                          $q->whereNotNull('status');
                                      },
                                      'attendances.calendar'
                                  ])
                           ->selectRaw(
                               'classes.* ,
                                                    CASE WHEN count_ex_absence.count IS NULL THEN 0 ELSE count_ex_absence.count END as ex_absence ,
                                                    CASE WHEN count_ex_tardy.count IS NULL THEN 0 ELSE count_ex_tardy.count END as ex_tardy,
                                                    CASE WHEN count_unex_absence.count IS NULL THEN 0 ELSE count_unex_absence.count END as unex_absence,
                                                    CASE WHEN count_unex_tardy.count IS NULL THEN 0 ELSE count_unex_tardy.count END as unex_tardy,
                                                    CASE WHEN count_present.count IS NULL THEN 0 ELSE count_present.count END as present'
                           )
                           ->leftJoin('terms', 'terms.id', '=', 'classes.term_id')
                           ->join('class_assignments', 'class_assignments.class_id', '=', 'classes.id')
                           ->where('class_assignments.user_id', $userSqlId)
                           ->leftJoinSub($this->_queryCountAttendanceViaStatusAndUserId(AttendanceConstant::EX_ABSENCE,
                                                                                        $userSqlId),
                                         'count_ex_absence', 'count_ex_absence.class_id', '=', 'classes.id')
                           ->leftJoinSub($this->_queryCountAttendanceViaStatusAndUserId(AttendanceConstant::EX_TARDY,
                                                                                        $userSqlId),
                                         'count_ex_tardy', 'count_ex_tardy.class_id', '=', 'classes.id')
                           ->leftJoinSub($this->_queryCountAttendanceViaStatusAndUserId(AttendanceConstant::UNEX_ABSENCE,
                                                                                        $userSqlId),
                                         'count_unex_absence', 'count_unex_absence.class_id', '=', 'classes.id')
                           ->leftJoinSub($this->_queryCountAttendanceViaStatusAndUserId(AttendanceConstant::UNEX_TARDY,
                                                                                        $userSqlId),
                                         'count_unex_tardy', 'count_unex_tardy.class_id', '=', 'classes.id')
                           ->leftJoinSub($this->_queryCountAttendanceViaStatusAndUserId(AttendanceConstant::PRESENT,
                                                                                        $userSqlId),
                                         'count_present', 'count_present.class_id', '=', 'classes.id')
                           ->orderBy('classes.name', 'ASC')
                           ->distinct('classes.name')
                           ->get();

        $groupClassViaTermId = $classes->groupBy('term_id');
        $classIds            = array_column($classes->toArray(), 'id');

        $numberCalendarViaClassIds = $this->countInstructionalDaysViaUserId($userSqlId);

        $countPassCalendar = $this->countPassInstructionalDaysViaUserId($userSqlId);

        $arrSessionViaTerm      = array_column($numberCalendarViaClassIds, 'count', '_id');
        $arrPassCalendarViaTerm = array_column($countPassCalendar, 'count', '_id');
        $terms                  = (new TermService())
            ->queryGetViaStudentSqlId($userSqlId)
            ->selectRaw(
                'terms.* ,
                CASE WHEN count_present.countPresent IS NULL THEN 0 ELSE count_present.countPresent END as present_days'
            )
            ->leftJoinSub($this->_queryCountPresentAttendanceViaUserId($userSqlId), 'count_present', function ($join) {
                $join->on('count_present.term_id', 'terms.id');
            })
            ->orderBy('terms.start_date', 'DESC')
            ->get();

        foreach ($terms as $term) {
            $termId                        = $term->{'id'};
            $term->instructional_days      = $arrSessionViaTerm[$termId] ?? 0;
            $term->instructional_pass_days = $arrPassCalendarViaTerm[$termId] ?? 0;
            $term->{'classes'}             = $groupClassViaTermId[$termId] ?? null;
        }

        if (!isset($arrSessionViaTerm['']))
            return $terms;

        $undefinedTerm = [
            'instructional_days'      => $arrSessionViaTerm[''] ?? null,
            'instructional_pass_days' => $arrPassCalendarViaTerm[''] ?? null,
            'classes'                 => $groupClassViaTermId[''] ?? null,
            'name'                    => 'Undefined',
            'present_days'            => AttendanceSQL::query()
                                                      ->join('classes', 'classes.id', '=', 'attendances.class_id')
                                                      ->whereNull('classes.term_id')
                                                      ->whereIn('classes.id', $classIds)
                                                      ->where('attendances.status', AttendanceConstant::PRESENT)
                                                      ->where('attendances.user_id', $userSqlId)
                                                      ->count()
        ];

        $terms->push($undefinedTerm);

        return $terms;
    }

    /**
     * @Author Edogawa Conan
     * @Date   Apr 26, 2022
     *
     * @param string $group
     * @param int    $userSqlId
     *
     * @return Builder|AttendanceSQL
     */
    private function _queryCountAttendanceViaGroupAndUserId(string $group, int $userSqlId): Builder|AttendanceSQL
    {
        return AttendanceSQL::query()
                            ->selectRaw('class_id , COUNT(id) as count')
                            ->whereGroup($group)
                            ->whereUserId($userSqlId)
                            ->where('status', '<>', AttendanceConstant::PRESENT)
                            ->groupBy('class_id');
    }

    /**
     * @Author Edogawa Conan
     * @Date   Apr 27, 2022
     *
     * @param int $userSqlId
     *
     * @return Builder|AttendanceSQL
     */
    public function _queryCountPresentAttendanceViaUserId(int $userSqlId): Builder|AttendanceSQL
    {
        return AttendanceSQL::query()
                            ->selectRaw('terms.id as term_id , count(attendances.id) as countPresent')
                            ->join('classes', 'attendances.class_id', '=', 'classes.id')
                            ->join('terms', 'terms.id', '=', 'classes.term_id')
                            ->where('attendances.user_id', $userSqlId)
                            ->where('attendances.status', AttendanceConstant::PRESENT)
                            ->groupBy('terms.id');
    }

    public function insertParticipantToAttendance(array  $participants, UTCDateTime $startDate, UTCDateTime $endDate,
                                                  string $calendarId, int $zoomMeetingId, string $date): bool
    {
        $startDate      = Carbon::parse($startDate->toDateTime())->format('Y-m-d');
        $endDate        = Carbon::parse($endDate->toDateTime())->format('Y-m-d');
        $attendanceLogs = [];
        foreach ($participants as $participant) {
            $attendanceLogs[] = [
                'status'          => null,
                'zoom_meeting_id' => $zoomMeetingId,
                'user_uuid'       => $participant['user_uuid'],
                'class_id'        => $participant['class_id'] ?? null,
                'start'           => $startDate,
                'end'             => $endDate,
                'calendar_id'     => $calendarId,
                'group'           => null,
                'created_by'      => BaseService::currentUser()->id,
                'user_id'         => $participant['user_id'],
                'join_time'       => null,
                'leave_time'      => null,
                'date'            => $date
            ];
        }

        $this->model->insert($attendanceLogs ?? []);

        return true;
    }

    private function _queryCountAttendanceViaStatusAndUserId(string $status, int $userSqlId): Builder|AttendanceSQL
    {
        return AttendanceSQL::query()
                            ->selectRaw('class_id , COUNT(id) as count')
                            ->whereStatus($status)
                            ->whereUserId($userSqlId)
                            ->groupBy('class_id');
    }

    public function countInstructionalDaysViaUserId(int $userId)
    {
        $classIds = (new ClassService())->getClassByUserIdAndStatus($userId, StatusConstant::ACTIVE)->pluck('class_id')->toArray();

        return CalendarNoSQL::query()->raw(function ($collection) use ($classIds) {
            return $collection->aggregate([
                                              //where in...
                                              [
                                                  '$match' => [
                                                      'class_id' => ['$in' => $classIds]
                                                  ]
                                              ],
                                              [
                                                  '$group' => [
                                                      '_id'   => '$term_id',
                                                      'count' => ['$sum' => 1]
                                                  ]
                                              ]
                                          ]);
        })->toArray();
    }

    public function countPassInstructionalDaysViaUserId(int $userId)
    {
        $classIds = (new ClassService())->getClassByUserIdAndStatus($userId, StatusConstant::ACTIVE)->pluck('class_id')->toArray();
        $today    = Carbon::now()->toDateString();

        return CalendarNoSQL::query()
                            ->where('end', '<=', $today)
                            ->orWhere('end', '<=', new UTCDateTime(Carbon::parse($today)))
                            ->raw(function ($collection) use ($classIds, $today) {
                                return $collection->aggregate([
                                                                  //where in...
                                                                  [
                                                                      '$match' => [
                                                                          'class_id' => ['$in' => $classIds],
                                                                          '$or'      => [
                                                                              [
                                                                                  'end' => [
                                                                                      '$lt' => new UTCDateTime(Carbon::parse($today)),
                                                                                  ]
                                                                              ],
                                                                              [
                                                                                  'end' => [
                                                                                      '$lt' => $today,
                                                                                  ]
                                                                              ],
                                                                          ]
                                                                      ]
                                                                  ],
                                                                  [
                                                                      '$group' => [
                                                                          '_id'   => '$term_id',
                                                                          'count' => ['$sum' => 1]
                                                                      ]
                                                                  ]
                                                              ]);
                            })->toArray();

    }
}
