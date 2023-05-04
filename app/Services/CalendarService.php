<?php


namespace App\Services;


use Carbon\Carbon;
use Carbon\CarbonPeriod;
use DB;
use Exception;
use Faker\Provider\Uuid;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use JetBrains\PhpStorm\ArrayShape;
use MongoDB\BSON\UTCDateTime;
use stdClass;
use Throwable;
use YaangVu\Constant\ClassAssignmentConstant;
use YaangVu\Constant\CodeConstant;
use YaangVu\Constant\GuestObjectInviteToZoomConstant;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Constant\StatusConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\ForbiddenException;
use YaangVu\Exceptions\NotFoundException;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Helpers\QueryHelper;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Constants\CalendarRepeatTypeConstant;
use YaangVu\SisModel\App\Constants\CalendarTypeConstant;
use YaangVu\SisModel\App\Models\impl\AttendanceSQL;
use YaangVu\SisModel\App\Models\impl\CalendarNoSQL;
use YaangVu\SisModel\App\Models\impl\ClassAssignmentSQL;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\impl\JobNoSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Models\impl\UserSQL;
use YaangVu\SisModel\App\Models\impl\ZoomMeetingSQL;
use YaangVu\SisModel\App\Models\impl\ZoomParticipantSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class CalendarService extends BaseService
{
    use RoleAndPermissionTrait;

    protected array $typeSchoolEvent = [CalendarTypeConstant::EVENT, CalendarTypeConstant::HOLIDAY, CalendarTypeConstant::ACTIVITY];

    protected array $repeat = [CalendarRepeatTypeConstant::WEEKLY, CalendarRepeatTypeConstant::DAILY, CalendarRepeatTypeConstant::IRREGULARLY];

    protected array $dayOfWeek = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function createModel(): void
    {
        $this->model = new CalendarNoSQL();
    }

    public function getAllWithoutPaginate(): array|Collection
    {
        $isCounselor = $this->hasAnyRole(RoleConstant::COUNSELOR);
        if (!$isCounselor && !$this->hasPermission(PermissionConstant::calendar(PermissionActionConstant::LIST)))
            throw new ForbiddenException(__('calendar.forbidden_calendar_list'), new Exception());

        $this->preGetAll();
        $request    = \request()->all();
        $roleActive = $request['role_active'] ?? null;

        if ($isCounselor && $roleActive == RoleConstant::COUNSELOR) {
            $assignedStudentUuids = (new UserService())->getByUuid(self::currentUser()->uuid)
                    ?->assigned_student_uuids ?? null;
            $studentMongoIds      = UserNoSQL::query()->whereIn('uuid', $assignedStudentUuids)->pluck('_id')->toArray();
        }

        $param = [
            'start'      => $request['start'] ?? Carbon::now()->toDateString(),
            'end'        => $request['end'] ?? Carbon::now()->toDateString(),
            'termId'     => $request['term_id'] ?? null,
            'classId'    => $request['classes__id'] ?? null,
            'studentIds' => ($request['student_id'] ?? null) ? [$request['student_id']] : ($studentMongoIds ?? null),
            'teacherId'  => $request['teacher_id'] ?? null,
            'type'       => $request['type'] ?? null
        ];

        $this->queryHelper->removeParam('start')
                          ->removeParam('end')
                          ->removeParam('student_id')
                          ->removeParam('teacher_id')
                          ->removeParam('type')
                          ->removeParam('role_active');

        $classIds = $this->queryHelper->buildQuery(new ClassSql())
                                      ->select('classes.*')
                                      ->leftJoin('class_assignments', 'class_assignments.class_id', '=', 'classes.id')
                                      ->when($param['studentIds'] || $param['teacherId'],
                                          function (EBuilder $q) use ($param) {
                                              $q->where(function (EBuilder $where) use ($param) {
                                                  $where->where('class_assignments.assignment', '=',
                                                                ClassAssignmentConstant::STUDENT);
                                                  $where->whereIn('class_assignments.user_id',
                                                                  $param['studentIds'] ? (new UserService())->getUserSqlViaIds($param['studentIds'])
                                                                                                            ?->pluck('id') : []);
                                              });
                                              $q->orWhere(function (EBuilder $where) use ($param) {
                                                  $where->whereIn('class_assignments.assignment',
                                                                  [ClassAssignmentConstant::PRIMARY_TEACHER, ClassAssignmentConstant::SECONDARY_TEACHER]);
                                                  $where->where('class_assignments.user_id', '=',
                                                                $param['teacherId'] ? (new UserService())->getUserSqlViaId($param['teacherId'])?->id : null);
                                              });
                                          })
                                      ->groupBy('classes.id')
                                      ->pluck('classes.id');
        $data     = $this->model
            ->with([
                       'user',
                       'class.teachers' => function ($q) {
                           $q->where('assignment', ClassAssignmentConstant::PRIMARY_TEACHER);
                       },
                       'class.teachers.users',
                       'class.teachers.users.userNoSql',
                       'zoomMeeting.hostZoomMeeting.host',
                       'attendances'    => function ($q) {
                           $q->where(function ($query) {
                               $query->whereNotNull('zoom_meeting_id')
                                     ->whereNull('status');
                           })->orWhere(function ($query) {
                               $query->whereNull('zoom_meeting_id')
                                     ->whereNotNull('status');
                           });
                       }
                   ])
            ->where(CodeConstant::UUID, SchoolServiceProvider::$currentSchool->uuid)
            ->where(function ($where) use ($param) {
                $UTCBetween  = [new UTCDateTime(Carbon::parse($param['start'])),
                                new UTCDateTime(Carbon::parse($param['end']))];
                $dateBetween = [$param['start'], $param['end']];

                $where
                    ->where(function ($query) use ($UTCBetween, $dateBetween) {
                        $query->whereBetween('start', $UTCBetween);
                        $query->orWhereBetween('start', $dateBetween);
                        $query->orWhereBetween('end', $UTCBetween);
                        $query->orWhereBetween('end', $dateBetween);
                    })
                    ->orWhere(function ($query) use ($param) {
                        $query->where('start', '<=', $param['start'])
                              ->where('end', '>=', $param['end']);
                    })
                    ->orWhere(function ($query) use ($param) {
                        $query->where('start', '<=', new UTCDateTime(Carbon::parse($param['start'])))
                              ->where('end', '>=', new UTCDateTime(Carbon::parse($param['end'])));
                    });

                // $where->whereBetween('start', $UTCBetween);
                // $where->orWhereBetween('start', $dateBetween);
                // $where->orWhereBetween('end', $UTCBetween);
                // $where->orWhereBetween('end', $dateBetween);

            })
            ->when($param['termId'] || $param['classId'] || $param['studentIds'] || $param['teacherId'],
                function ($q) use ($classIds, $param) {
                    $classId     = (int)$param['classId'];
                    $calendarIds = CalendarNoSQL::raw(function ($collection) use ($classId) {
                        return $collection->aggregate([
                                                          [
                                                              '$match' => [
                                                                  'class_ids' => ['$eq' => $classId]
                                                              ]
                                                          ]
                                                      ]);
                    })->pluck('_id')->toArray();
                    if ($param['type'] !== CalendarTypeConstant::EVENT)
                        $q->orwhereIn('class_id', $classIds)->orWhereIn('_id', $calendarIds);
                })
            ->when($param['type'], function ($q) use ($param) {
                $q->where('type', $param['type']);
            })
            ->whereNull('is_view_calendar')
            ->orderBy('start');

        try {
            $response = $data->get();
            $this->postGetAll($response);

            return $response;
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    public function getAllWithoutPaginateForDashboard(): array|Collection
    {
        $isCounselor = $this->hasAnyRole(RoleConstant::COUNSELOR);
        if (!$isCounselor && !$this->hasPermission(PermissionConstant::calendar(PermissionActionConstant::LIST)))
            throw new ForbiddenException(__('calendar.forbidden_calendar_list'), new Exception());

        $this->preGetAll();
        $request    = \request()->all();
        $roleActive = $request['role_active'] ?? null;

        if ($isCounselor && $roleActive == RoleConstant::COUNSELOR) {
            $assignedStudentUuids = (new UserService())->getByUuid(self::currentUser()->uuid)
                    ?->assigned_student_uuids ?? null;
            $studentMongoIds      = UserNoSQL::query()->whereIn('uuid', $assignedStudentUuids)->pluck('_id')->toArray();
        }

        $param = [
            'start'      => $request['start'] ?? Carbon::now()->toDateTimeString(),
            'end'        => $request['end'] ?? Carbon::now()->toDateTimeString(),
            'start_date' => $request['start_date'] ?? Carbon::now()->toDateTime(),
            'end_date'   => $request['end_date'] ?? Carbon::now()->toDateTime(),
            'termId'     => $request['term_id'] ?? null,
            'classId'    => $request['classes__id'] ?? null,
            'studentIds' => ($request['student_id'] ?? null) ? [$request['student_id']] : ($studentMongoIds ?? null),
            'teacherId'  => $request['teacher_id'] ?? null,
            'type'       => $request['type'] ?? null
        ];

        $this->queryHelper->removeParam('start')
                          ->removeParam('end')
                          ->removeParam('student_id')
                          ->removeParam('teacher_id')
                          ->removeParam('type')
                          ->removeParam('role_active');

        $classIds = $this->queryHelper->buildQuery(new ClassSql())
                                      ->select('classes.*')
                                      ->leftJoin('class_assignments', 'class_assignments.class_id', '=', 'classes.id')
                                      ->when($param['studentIds'] || $param['teacherId'],
                                          function (EBuilder $q) use ($param) {
                                              $q->where(function (EBuilder $where) use ($param) {
                                                  $where->where('class_assignments.assignment', '=',
                                                                ClassAssignmentConstant::STUDENT);
                                                  $where->whereIn('class_assignments.user_id',
                                                                  $param['studentIds'] ? (new UserService())->getUserSqlViaIds($param['studentIds'])
                                                                                                            ?->pluck('id') : []);
                                              });
                                              $q->orWhere(function (EBuilder $where) use ($param) {
                                                  $where->whereIn('class_assignments.assignment',
                                                                  [ClassAssignmentConstant::PRIMARY_TEACHER, ClassAssignmentConstant::SECONDARY_TEACHER]);
                                                  $where->where('class_assignments.user_id', '=',
                                                                $param['teacherId'] ? (new UserService())->getUserSqlViaId($param['teacherId'])?->id : null);
                                              });
                                          })
                                      ->groupBy('classes.id')
                                      ->pluck('classes.id');
        $data     = $this->model
            ->with([
                       'user',
                       'class.teachers' => function ($q) {
                           $q->where('assignment', ClassAssignmentConstant::PRIMARY_TEACHER);
                       },
                       'class.teachers.users',
                       'class.teachers.users.userNoSql',
                       'zoomMeeting.hostZoomMeeting.host',
                       'attendances'    => function ($q) {
                           $q->where(function ($query) {
                               $query->whereNotNull('zoom_meeting_id')
                                     ->whereNull('status');
                           })->orWhere(function ($query) {
                               $query->whereNull('zoom_meeting_id')
                                     ->whereNotNull('status');
                           });
                       }
                   ])
            ->where(CodeConstant::UUID, SchoolServiceProvider::$currentSchool->uuid)
            ->where(function ($where) use ($param) {
                $UTCBetween = [new UTCDateTime(Carbon::parse($param['start'])),
                               new UTCDateTime(Carbon::parse($param['end']))];

                $dateBetween = [$param['start_date'], $param['end_date']];

                $where
                    ->where(function ($query) use ($UTCBetween, $dateBetween) {
                        $query->whereBetween('start', $UTCBetween);
                        $query->whereBetween('end', $UTCBetween);
                        // $query->orWhereBetween('start', $dateBetween);
                        // $query->orWhereBetween('end', $dateBetween);
                    })
                    ->orWhere(function ($query) use ($param, $dateBetween) {
                        $query->whereBetween('start', $dateBetween);
                        $query->whereBetween('end', $dateBetween);
                        // $query->where('start', '<=', $param['start_date'])
                        //       ->where('end', '>=', $param['end_date']);
                    })
                    ->orWhere(function ($query) use ($param) {
                        $query->whereDate('start', '<=', new UTCDateTime(Carbon::parse($param['start'])))
                              ->whereDate('end', '>=', new UTCDateTime(Carbon::parse($param['end'])));
                    });

                // $where->whereBetween('start', $UTCBetween);
                // $where->orWhereBetween('start', $dateBetween);
                // $where->orWhereBetween('end', $UTCBetween);
                // $where->orWhereBetween('end', $dateBetween);

            })
            ->when($param['termId'] || $param['classId'] || $param['studentIds'] || $param['teacherId'],
                function ($q) use ($classIds, $param) {
                    $classId     = (int)$param['classId'];
                    $calendarIds = CalendarNoSQL::raw(function ($collection) use ($classId) {
                        return $collection->aggregate([
                                                          [
                                                              '$match' => [
                                                                  'class_ids' => ['$eq' => $classId]
                                                              ]
                                                          ]
                                                      ]);
                    })->pluck('_id')->toArray();
                    if ($param['type'] !== CalendarTypeConstant::EVENT)
                        $q->orwhereIn('class_id', $classIds)->orWhereIn('_id', $calendarIds);
                })
            ->when($param['type'], function ($q) use ($param) {
                $q->where('type', $param['type']);
            })
            ->whereNull('is_view_calendar')
            ->orderBy('start');

        try {
            $response = $data->get();
            $this->postGetAll($response);

            return $response;
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    public function getAllWithoutPaginateForCurrentUserDashboard(): Collection|array
    {
        $isStudent = $this->isStudent();
        $isTeacher = $this->hasAnyRole(RoleConstant::TEACHER);
        $isFamily  = $this->hasAnyRole(RoleConstant::FAMILY);
        if (!$isStudent && !$isTeacher && !$isFamily &&
            !$this->hasPermission(PermissionConstant::calendar(PermissionActionConstant::LIST)))
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        $request = \request()->all();

        $start       = $request['start'] ?? Carbon::now()->toDateString();
        $end         = $request['end'] ?? Carbon::now()->toDateString();
        $startDate   = $request['start_date'] ?? Carbon::now()->toDateTimeString();
        $endDate     = $request['end_date'] ?? Carbon::now()->toDateTimeString();
        $type        = $request['type'] ?? null;
        $studentId   = $request['student_id'] ?? null;
        $classId     = ($request['class_id'] ?? null) ? [$request['class_id']] : null;
        $programId   = $request['program_id'] ?? null;
        $childrenId  = $request['children_id'] ?? null;
        $roleActive  = $request['role_active'] ?? null;
        $currentUser = self::currentUser();

        if ($isFamily && $roleActive == RoleConstant::FAMILY) {
            $currentUser = (new UserService())->get($childrenId)->userSql;
            $isStudent   = true;
        }

        if ($programId)
            $classId = ClassSQL::with([])
                               ->join('subjects', 'subjects.id', '=', 'classes.subject_id')
                               ->join('graduation_category_subject as gcs', 'gcs.subject_id', '=', 'subjects.id')
                               ->join('graduation_categories as gc', 'gc.id', '=', 'gcs.graduation_category_id')
                               ->join('program_graduation_category as pgc', 'pgc.graduation_category_id', '=', 'gc.id')
                               ->join('programs', 'programs.id', '=', 'pgc.program_id')
                               ->where('programs.id', $programId)
                               ->where('classes.school_id', SchoolServiceProvider::$currentSchool->id)
                               ->pluck('classes.id')
                               ->toArray();

        $classIds = ClassAssignmentSQL::query()->select('class_assignments.*')
                                      ->when(!$studentId, function (EBuilder $q) use ($currentUser) {
                                          $q->where('class_assignments.user_id', $currentUser->id);
                                      })
                                      ->join('classes', 'classes.id', '=', 'class_assignments.class_id')
                                      ->where('classes.status', StatusConstant::ON_GOING)
                                      ->where('class_assignments.status', StatusConstant::ACTIVE)
                                      ->when($isStudent, function (EBuilder $q) {
                                          $q->where('class_assignments.assignment',
                                                    ClassAssignmentConstant::STUDENT);
                                      })
                                      ->when($isTeacher && $roleActive == RoleConstant::TEACHER,
                                          function (EBuilder $q) use ($studentId) {
                                              if ($studentId)
                                                  $q->where('class_assignments.user_id',
                                                            (new UserService())->getUserSqlViaId($studentId)?->id)
                                                    ->where('class_assignments.assignment',
                                                            ClassAssignmentConstant::STUDENT);
                                              else
                                                  $q->whereIn('class_assignments.assignment',
                                                              ClassAssignmentConstant::TEACHER);
                                          })
                                      ->when($classId, function (EBuilder $q) use ($classId) {
                                          $q->whereIn('class_assignments.class_id', $classId);
                                      })
                                      ->pluck('class_assignments.class_id');


        $zoomMeetingIds = ZoomParticipantSQL::query()
                                            ->join('zoom_meetings', 'zoom_meetings.id', '=',
                                                   'zoom_participants.zoom_meeting_id')
                                            ->when($roleActive, function ($q) use ($roleActive) {
                                                if ($roleActive == RoleConstant::TEACHER) {
                                                    $q->where('zoom_meetings.type_guest',
                                                              GuestObjectInviteToZoomConstant::CLASSES);
                                                }
                                                if ($roleActive == RoleConstant::COUNSELOR) {
                                                    $q->where('zoom_meetings.type_guest',
                                                              GuestObjectInviteToZoomConstant::USER_INFORMATION);
                                                }
                                            })
                                            ->where('user_uuid', $currentUser?->uuid)
                                            ->pluck('zoom_meeting_id')
                                            ->toArray();

        $zoomMeetingEvent = $this->model
            ->with([
                       'user',
                       'class.teachers' => function ($q) {
                           $q->where('assignment', ClassAssignmentConstant::PRIMARY_TEACHER);
                       },
                       'class.teachers.users',
                       'class.teachers.users.userNoSql',
                       'zoomMeeting.hostZoomMeeting.host',
                       'attendances'    => function ($q) {
                           $q->where(function ($query) {
                               $query->whereNotNull('zoom_meeting_id')
                                     ->whereNull('status');
                           })->orWhere(function ($query) {
                               $query->whereNull('zoom_meeting_id')
                                     ->whereNotNull('status');
                           });
                       }
                   ])
            ->whereIn('class_id', $classIds)
            ->where('type', CalendarTypeConstant::VIDEO_CONFERENCE)
            ->whereIn('zoom_meeting_id', $zoomMeetingIds)
            ->where(CodeConstant::UUID, SchoolServiceProvider::$currentSchool->uuid)
            ->where(function (EBuilder $wh) use ($start, $end, $startDate, $endDate) {
                $UTCBetween = [new UTCDateTime(Carbon::parse($start)),
                               new UTCDateTime(Carbon::parse($end))];

                $dateBetween = [$startDate, $endDate];
                $wh
                    ->where(function ($query) use ($UTCBetween, $dateBetween) {
                        $query->whereBetween('start', $UTCBetween);
                        $query->whereBetween('end', $UTCBetween);
                    })
                    ->orWhere(function ($query) use ($dateBetween) {
                        $query->whereBetween('start', $dateBetween);
                        $query->whereBetween('end', $dateBetween);
                    })
                    ->orWhere(function ($query) use ($start, $end) {
                        $query->whereDate('start', '<=', new UTCDateTime(Carbon::parse($start)))
                              ->whereDate('end', '>=', new UTCDateTime(Carbon::parse($end)));
                    });
            })
            ->whereNull('is_view_calendar')
            ->get();

        $schoolEvent = $this->model
            ->with([
                       'user',
                       'class.teachers' => function ($q) {
                           $q->where('assignment', ClassAssignmentConstant::PRIMARY_TEACHER);
                       },
                       'class.teachers.users.userNoSql',
                       'attendances'
                   ])
            ->where('type', CalendarTypeConstant::EVENT)
            ->where(CodeConstant::UUID, SchoolServiceProvider::$currentSchool->uuid)
            ->where(function (EBuilder $wh) use ($start, $end, $startDate, $endDate) {
                $UTCBetween = [new UTCDateTime(Carbon::parse($start)),
                               new UTCDateTime(Carbon::parse($end))];

                $dateBetween = [$startDate, $endDate];
                $wh
                    ->where(function ($query) use ($UTCBetween, $dateBetween) {
                        $query->whereBetween('start', $UTCBetween);
                        $query->whereBetween('end', $UTCBetween);
                    })
                    ->orWhere(function ($query) use ($dateBetween) {
                        $query->whereBetween('start', $dateBetween);
                        $query->whereBetween('end', $dateBetween);
                    })
                    ->orWhere(function ($query) use ($start, $end) {
                        $query->whereDate('start', '<=', new UTCDateTime(Carbon::parse($start)))
                              ->whereDate('end', '>=', new UTCDateTime(Carbon::parse($end)));
                    });
                // $wh->whereBetween('start', $UTCBetween);
                // $wh->orWhereBetween('start', $dateBetween);
                // $wh->orWhereBetween('end', $UTCBetween);
                // $wh->orWhereBetween('end', $dateBetween);
            })
            ->get();

        $schoolSchedule = $this->model
            ->with([
                       'user',
                       'class.teachers' => function ($q) {
                           $q->where('assignment', ClassAssignmentConstant::PRIMARY_TEACHER);
                       },
                       'class.teachers.users',
                       'attendances'
                   ])
            ->whereIn('class_id', $classIds)
            ->where('type', CalendarTypeConstant::SCHEDULE)
            ->where(CodeConstant::UUID, SchoolServiceProvider::$currentSchool->uuid)
            ->where(function (EBuilder $wh) use ($start, $end, $startDate, $endDate) {
                $UTCBetween = [new UTCDateTime(Carbon::parse($start)),
                               new UTCDateTime(Carbon::parse($end))];

                $dateBetween = [$startDate, $endDate];
                $wh
                    ->where(function ($query) use ($UTCBetween, $dateBetween) {
                        $query->whereBetween('start', $UTCBetween);
                        $query->whereBetween('end', $UTCBetween);
                    })
                    ->orWhere(function ($query) use ($dateBetween) {
                        $query->whereBetween('start', $dateBetween);
                        $query->whereBetween('end', $dateBetween);
                    })
                    ->orWhere(function ($query) use ($start, $end) {
                        $query->whereDate('start', '<=', new UTCDateTime(Carbon::parse($start)))
                              ->whereDate('end', '>=', new UTCDateTime(Carbon::parse($end)));
                    });
                // $wh->whereBetween('start', $UTCBetween);
                // $wh->orWhereBetween('start', $dateBetween);
                // $wh->orWhereBetween('end', $UTCBetween);
                // $wh->orWhereBetween('end', $dateBetween);
            })
            ->get();

        return match ($type) {
            CalendarTypeConstant::EVENT => $schoolEvent,
            CalendarTypeConstant::SCHEDULE => $schoolSchedule,
            CalendarTypeConstant::VIDEO_CONFERENCE => $zoomMeetingEvent,
            default => $schoolEvent->merge($schoolSchedule->all())->merge($zoomMeetingEvent->all())
        };
    }

    public function getAllWithoutPaginateForCurrentUser(): Collection|array
    {
        $isStudent = $this->isStudent();
        $isTeacher = $this->hasAnyRole(RoleConstant::TEACHER);
        $isFamily  = $this->hasAnyRole(RoleConstant::FAMILY);
        if (!$isStudent && !$isTeacher && !$isFamily &&
            !$this->hasPermission(PermissionConstant::calendar(PermissionActionConstant::LIST)))
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        $request = \request()->all();

        $start       = $request['start'] ?? Carbon::now()->toDateString();
        $end         = $request['end'] ?? Carbon::now()->toDateString();
        $type        = $request['type'] ?? null;
        $studentId   = $request['student_id'] ?? null;
        $classId     = ($request['class_id'] ?? null) ? [$request['class_id']] : null;
        $programId   = $request['program_id'] ?? null;
        $childrenId  = $request['children_id'] ?? null;
        $roleActive  = $request['role_active'] ?? null;
        $currentUser = self::currentUser();

        if ($isFamily && $roleActive == RoleConstant::FAMILY) {
            $currentUser = (new UserService())->get($childrenId)->userSql;
            $isStudent   = true;
        }

        if ($programId)
            $classId = ClassSQL::with([])
                               ->join('subjects', 'subjects.id', '=', 'classes.subject_id')
                               ->join('graduation_category_subject as gcs', 'gcs.subject_id', '=', 'subjects.id')
                               ->join('graduation_categories as gc', 'gc.id', '=', 'gcs.graduation_category_id')
                               ->join('program_graduation_category as pgc', 'pgc.graduation_category_id', '=', 'gc.id')
                               ->join('programs', 'programs.id', '=', 'pgc.program_id')
                               ->where('programs.id', $programId)
                               ->where('classes.school_id', SchoolServiceProvider::$currentSchool->id)
                               ->pluck('classes.id')
                               ->toArray();

        $classIds = ClassAssignmentSQL::query()->select('class_assignments.*')
                                      ->when(!$studentId, function (EBuilder $q) use ($currentUser) {
                                          $q->where('class_assignments.user_id', $currentUser->id);
                                      })
                                      ->join('classes', 'classes.id', '=', 'class_assignments.class_id')
                                      ->where('classes.status', StatusConstant::ON_GOING)
                                      ->where('class_assignments.status', StatusConstant::ACTIVE)
                                      ->when($isStudent, function (EBuilder $q) {
                                          $q->where('class_assignments.assignment',
                                                    ClassAssignmentConstant::STUDENT);
                                      })
                                      ->when($isTeacher && $roleActive == RoleConstant::TEACHER,
                                          function (EBuilder $q) use ($studentId) {
                                              if ($studentId)
                                                  $q->where('class_assignments.user_id',
                                                            (new UserService())->getUserSqlViaId($studentId)?->id)
                                                    ->where('class_assignments.assignment',
                                                            ClassAssignmentConstant::STUDENT);
                                              else
                                                  $q->whereIn('class_assignments.assignment',
                                                              ClassAssignmentConstant::TEACHER);
                                          })
                                      ->when($classId, function (EBuilder $q) use ($classId) {
                                          $q->whereIn('class_assignments.class_id', $classId);
                                      })
                                      ->pluck('class_assignments.class_id');


        $zoomMeetingIds = ZoomParticipantSQL::query()
                                            ->join('zoom_meetings', 'zoom_meetings.id', '=',
                                                   'zoom_participants.zoom_meeting_id')
                                            ->when($roleActive, function ($q) use ($roleActive) {
                                                if ($roleActive == RoleConstant::TEACHER) {
                                                    $q->where('zoom_meetings.type_guest',
                                                              GuestObjectInviteToZoomConstant::CLASSES);
                                                }
                                                if ($roleActive == RoleConstant::COUNSELOR) {
                                                    $q->where('zoom_meetings.type_guest',
                                                              GuestObjectInviteToZoomConstant::USER_INFORMATION);
                                                }
                                            })
                                            ->where('user_uuid', $currentUser?->uuid)
                                            ->pluck('zoom_meeting_id')
                                            ->toArray();

        $zoomMeetingEvent = $this->model
            ->with([
                       'user',
                       'class.teachers' => function ($q) {
                           $q->where('assignment', ClassAssignmentConstant::PRIMARY_TEACHER);
                       },
                       'class.teachers.users',
                       'class.teachers.users.userNoSql',
                       'zoomMeeting.hostZoomMeeting.host',
                       'attendances'    => function ($q) {
                           $q->where(function ($query) {
                               $query->whereNotNull('zoom_meeting_id')
                                     ->whereNull('status');
                           })->orWhere(function ($query) {
                               $query->whereNull('zoom_meeting_id')
                                     ->whereNotNull('status');
                           });
                       }
                   ])
            ->whereIn('class_id', $classIds)
            ->where('type', CalendarTypeConstant::VIDEO_CONFERENCE)
            ->whereIn('zoom_meeting_id', $zoomMeetingIds)
            ->where(CodeConstant::UUID, SchoolServiceProvider::$currentSchool->uuid)
            ->where(function (EBuilder $wh) use ($start, $end) {
                $UTCBetween = [new UTCDateTime(Carbon::parse($start)),
                               new UTCDateTime(Carbon::parse($end))];

                $dateBetween = [$start, $end];
                $wh
                    ->where(function ($query) use ($UTCBetween, $dateBetween) {
                        $query->whereBetween('start', $UTCBetween);
                        $query->orWhereBetween('start', $dateBetween);
                        $query->orWhereBetween('end', $UTCBetween);
                        $query->orWhereBetween('end', $dateBetween);
                    })
                    ->orWhere(function ($query) use ($start, $end) {
                        $query->where('start', '<=', $start)
                              ->where('end', '>=', $end);
                    })
                    ->orWhere(function ($query) use ($start, $end) {
                        $query->where('start', '<=', new UTCDateTime(Carbon::parse($start)))
                              ->where('end', '>=', new UTCDateTime(Carbon::parse($end)));
                    });
            })
            ->whereNull('is_view_calendar')
            ->get();

        $schoolEvent = $this->model
            ->with([
                       'user',
                       'class.teachers' => function ($q) {
                           $q->where('assignment', ClassAssignmentConstant::PRIMARY_TEACHER);
                       },
                       'class.teachers.users.userNoSql',
                       'attendances'
                   ])
            ->where('type', CalendarTypeConstant::EVENT)
            ->where(CodeConstant::UUID, SchoolServiceProvider::$currentSchool->uuid)
            ->where(function (EBuilder $wh) use ($start, $end) {
                $UTCBetween = [new UTCDateTime(Carbon::parse($start)),
                               new UTCDateTime(Carbon::parse($end))];

                $dateBetween = [$start, $end];
                $wh
                    ->where(function ($query) use ($UTCBetween, $dateBetween) {
                        $query->whereBetween('start', $UTCBetween);
                        $query->orWhereBetween('start', $dateBetween);
                        $query->orWhereBetween('end', $UTCBetween);
                        $query->orWhereBetween('end', $dateBetween);
                    })
                    ->orWhere(function ($query) use ($start, $end) {
                        $query->where('start', '<=', $start)
                              ->where('end', '>=', $end);
                    })
                    ->orWhere(function ($query) use ($start, $end) {
                        $query->where('start', '<=', new UTCDateTime(Carbon::parse($start)))
                              ->where('end', '>=', new UTCDateTime(Carbon::parse($end)));
                    });
                // $wh->whereBetween('start', $UTCBetween);
                // $wh->orWhereBetween('start', $dateBetween);
                // $wh->orWhereBetween('end', $UTCBetween);
                // $wh->orWhereBetween('end', $dateBetween);
            })
            ->get();

        $schoolSchedule = $this->model
            ->with([
                       'user',
                       'class.teachers' => function ($q) {
                           $q->where('assignment', ClassAssignmentConstant::PRIMARY_TEACHER);
                       },
                       'class.teachers.users',
                       'attendances'
                   ])
            ->whereIn('class_id', $classIds)
            ->where('type', CalendarTypeConstant::SCHEDULE)
            ->where(CodeConstant::UUID, SchoolServiceProvider::$currentSchool->uuid)
            ->where(function (EBuilder $wh) use ($start, $end) {
                $UTCBetween = [new UTCDateTime(Carbon::parse($start)),
                               new UTCDateTime(Carbon::parse($end))];

                $dateBetween = [$start, $end];
                $wh
                    ->where(function ($query) use ($UTCBetween, $dateBetween) {
                        $query->whereBetween('start', $UTCBetween);
                        $query->orWhereBetween('start', $dateBetween);
                        $query->orWhereBetween('end', $UTCBetween);
                        $query->orWhereBetween('end', $dateBetween);
                    })
                    ->orWhere(function ($query) use ($start, $end) {
                        $query->where('start', '<=', $start)
                              ->where('end', '>=', $end);
                    })
                    ->orWhere(function ($query) use ($start, $end) {
                        $query->where('start', '<=', new UTCDateTime(Carbon::parse($start)))
                              ->where('end', '>=', new UTCDateTime(Carbon::parse($end)));
                    });
                // $wh->whereBetween('start', $UTCBetween);
                // $wh->orWhereBetween('start', $dateBetween);
                // $wh->orWhereBetween('end', $UTCBetween);
                // $wh->orWhereBetween('end', $dateBetween);
            })
            ->get();

        return match ($type) {
            CalendarTypeConstant::EVENT => $schoolEvent,
            CalendarTypeConstant::SCHEDULE => $schoolSchedule,
            CalendarTypeConstant::VIDEO_CONFERENCE => $zoomMeetingEvent,
            default => $schoolEvent->merge($schoolSchedule->all())->merge($zoomMeetingEvent->all())
        };
    }

    /**
     * @param string $id
     * @param object $request
     *
     * @return Model
     * @throws Throwable
     */
    public function updateSchoolEvent(string $id, object $request): Model
    {
        if (!$this->hasPermission(PermissionConstant::calendar(PermissionActionConstant::EDIT)))
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());

        $calendar = $this->get($id);
        $result   = $this->addSchoolEvent($request);
        $calendar->delete();

        return $result;
    }

    /**
     * @Author Edogawa Conan
     * @Date   Aug 29, 2021
     *
     * @param int|string $id
     *
     * @return Model|CalendarNoSQL
     */
    public function get(int|string $id): Model|CalendarNoSQL
    {
        return parent::get($id);
    }

    public function addSchoolEvent(object $request): Model
    {
        if (!$this->hasPermission(PermissionConstant::calendar(PermissionActionConstant::ADD)))
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());

        $rule = [
            'name'       => 'required',
            'category'   => 'required|in:' . implode(',', $this->typeSchoolEvent),
            'is_all_day' => 'required|boolean',

        ];
        if ($request->is_all_day ?? null)
            $rule = array_merge($rule, [
                'start' => 'required|date_format:Y-m-d',
                'end'   => 'required|date_format:Y-m-d|after_or_equal:start'
            ]);
        else
            $rule = array_merge($rule, [
                'start' => 'required|date_format:Y-m-d H:i',
                'end'   => 'required|date_format:Y-m-d H:i|after:start'
            ]);

        $this->doValidate($request, $rule);

        if ($request instanceof Request)
            $request = (object)$request->toArray();

        if (!$request->is_all_day) {
            $request->start = new UTCDateTime(self::setTzUTCViaDate($request->start, $request->timezone ?? 'UTC'));
            $request->end   = new UTCDateTime(self::setTzUTCViaDate($request->end, $request->timezone ?? 'UTC'));
        }
        $request->type                 = CalendarTypeConstant::EVENT;
        $request->{CodeConstant::UUID} = SchoolServiceProvider::$currentSchool->uuid;

        return $this->add($request);
    }

    public static function setTzUTCViaDate($date, $timezone = 'UTC'): bool|Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i', $date, $timezone !== '' ? $timezone : null)->setTimezone('UTC');
    }

    /**
     * @param string $id
     * @param object $request
     *
     * @return Model
     */
    public function updateSingleEvent(string $id, object $request): Model
    {
        $calendar = $this->get($id);

        if ($calendar->class_id) {
            $classAssignment = (new ClassAssignmentService)->getViaClassIdAndAssignment(
                $calendar->class_id,
                ClassAssignmentConstant::TEACHER,
                self::currentUser()->id
            );
            if (!$this->isGod()
                && !($this->hasPermission(PermissionConstant::calendar(PermissionActionConstant::EDIT)) && $classAssignment))
                throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        } else {
            if (!$this->isGod() && !($this->hasPermission(PermissionConstant::calendar(PermissionActionConstant::EDIT))))
                throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }

        $rule = [
            'class_id'   => 'sometimes|exists:classes,id,deleted_at,NULL',
            'is_all_day' => 'sometimes|boolean',
        ];
        if (isset($request->is_all_day) && !$request->is_all_day)
            $rule = array_merge($rule, [
                'start' => 'required|date_format:Y-m-d H:i',
                'end'   => 'required|date_format:Y-m-d H:i|after:start'
            ]);
        else
            $rule = array_merge($rule, [
                'start' => 'required|date_format:Y-m-d',
                'end'   => 'required|date_format:Y-m-d|after_or_equal:start'
            ]);
        $this->doValidate($request, $rule);

        if ($request instanceof Request)
            $request = (object)$request->toArray();

        if (isset($request->is_all_day)) {
            if (!$request->is_all_day) {
                $start = new UTCDateTime(self::setTzUTCViaDate(
                    $request->start,
                    $request->timezone ?? 'UTC'
                )->setTimezone('UTC'));
                $end   = new UTCDateTime(self::setTzUTCViaDate(
                    $request->end,
                    $request->timezone ?? 'UTC'
                )->setTimezone('UTC'));
            }
        } else {
            if (Carbon::parse($calendar->start)->format('H:i') !== '00:00') {
                $start = new UTCDateTime(self::setTzUTCViaDateAndMinutes(
                    $request->start,
                    Carbon::parse($calendar->start)->format('H:i'),
                    $request->timezone ?? null)
                );
                $end   = new UTCDateTime(self::setTzUTCViaDateAndMinutes(
                    $request->start,
                    Carbon::parse($calendar->start)->format('H:i'),
                    $request->timezone ?? null)
                );
            }
        }

        $request->start = $start ?? $request->start;
        $request->end   = $end ?? $request->end;

        return $this->update($id, $request);
    }

    public static function setTzUTCViaDateAndMinutes($date, $minutes, $timezone = 'UTC'): bool|Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i', $date . ' ' . $minutes, $timezone !== '' ? $timezone : null)
                     ->setTimezone('UTC');
    }

    /**
     * @param string $id
     * @param object $request
     *
     * @return array
     * @throws Throwable
     */
    public function updateClassSchedule(string $id, object $request): array
    {
        $calendar        = $this->get($id);
        $classAssignment = (new ClassAssignmentService)->getViaClassIdAndAssignment(
            $calendar->class_id,
            ClassAssignmentConstant::TEACHER,
            self::currentUser()->id
        );

        if (!$this->isGod() &&
            !($this->hasPermission(PermissionConstant::calendar(PermissionActionConstant::EDIT)) && $classAssignment))
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());

        $this->doValidate($request, ['option' => 'required|in:This and the following schedules,All schedules']);
        $result = $this->addClassSchedule($request);
        if ($request->option == 'This and the following schedules') {
            $this->_deleteThisAndTheFollowingViaGroup($calendar->group, $calendar->start);
        } else {
            $this->_deleteAllScheduleViaGroup($calendar->group);
        }

        return $result;
    }

    /**
     * @param object $request
     *
     * @return array
     * @throws Throwable
     */
    public function addClassSchedule(object $request): array
    {
        if (!$this->hasPermission(PermissionConstant::calendar(PermissionActionConstant::ADD)))
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());

        $rules = [
            'class_id'   => 'required|exists:classes,id,deleted_at,NULL',
            'repeat'     => 'in:' . implode(',', $this->repeat),
            'is_all_day' => 'required|boolean'
        ];
        $this->doValidate($request, $rules);
        $class         = (new ClassService())->get($request->class_id);
        $classSchedule = match ($request->repeat) {
            CalendarRepeatTypeConstant::WEEKLY => $this->_addClassScheduleWithWeek($request, $class),
            CalendarRepeatTypeConstant::DAILY => $this->_addClassScheduleWithDaily($request, $class),
            default => $this->_addClassScheduleIrregularly($request, $class),
        };
        if (empty($classSchedule))
            throw new BadRequestException(__('validation.invalid'), new Exception());
        try {
            DB::beginTransaction();
            CalendarNoSQL::insert($classSchedule);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }

        return $classSchedule;
    }

    /**
     * @param object        $request
     * @param ClassSQL|null $class
     *
     * @return array
     */
    private function _addClassScheduleWithWeek(object $request, ClassSQL $class = null): array
    {
        $rule = [
            'start'                   => 'required|date_format:Y-m-d',
            'end'                     => 'required|date_format:Y-m-d|after_or_equal:start',
            'repeat_on'               => 'nullable|array',
            'repeat_on.*.day_of_week' => 'required|in:' . implode(',', $this->dayOfWeek)
        ];
        if (!$request->is_all_day)
            $rule = array_merge($rule, [
                'repeat_on.*.from_time' => 'required|date_format:H:i',
                'repeat_on.*.to_time'   => 'required|date_format:H:i|after:repeat_on.*.from_time',
            ]);
        $this->doValidate($request, $rule);

        if ($request instanceof Request)
            $request = (object)$request->toArray();

        $uuid = Uuid::uuid();

        $period = CarbonPeriod::create($request->start, $request->end);

        $data        = [];
        $dateOfWeeks = array_column($request->repeat_on, 'day_of_week');
        // Iterate over the period
        foreach ($period as $date) {
            if (in_array($date->format("l"), $dateOfWeeks)) {
                $dateString = $date->toDateString();
                if (!$request->is_all_day) {
                    $start = new UTCDateTime(self::setTzUTCViaDateAndMinutes(
                        $dateString,
                        trim($request->repeat_on[array_search($date->format("l"), $dateOfWeeks)]['from_time'] ?? null),
                        $request->timezone ?? null)
                    );
                    $end   = new UTCDateTime(self::setTzUTCViaDateAndMinutes(
                        $dateString,
                        trim($request->repeat_on[array_search($date->format("l"), $dateOfWeeks)]['to_time'] ?? null),
                        $request->timezone ?? null)
                    );
                }
                $data[] = [
                    'class_id'         => $class->id ?? null,
                    'term_id'          => $class->term_id ?? null,
                    'repeat'           => $request->repeat ?? null,
                    'type'             => CalendarTypeConstant::SCHEDULE,
                    'is_all_day'       => $request->is_all_day ?? null,
                    'start'            => $start ?? $dateString,
                    'end'              => $end ?? $dateString,
                    'location'         => $request->location ?? null,
                    'group'            => $uuid,
                    'timezone'         => $request->timezone ?? null,
                    'description'      => $request->description ?? null,
                    CodeConstant::UUID => SchoolServiceProvider::$currentSchool->uuid,
                    'created_by'       => self::currentUser()?->id ?? null,
                    'raw_data'         => $request,
                ];
            }
        }

        return $data;
    }

    /**
     * @param object        $request
     * @param ClassSQL|null $class
     *
     * @return array
     */
    private function _addClassScheduleWithDaily(object $request, ClassSQL $class = null): array
    {
        $rule = [
            'start' => 'required|date_format:Y-m-d',
            'end'   => 'required|date_format:Y-m-d|after_or_equal:start',
        ];
        if (!$request->is_all_day) {
            $rule = array_merge($rule, [
                'from_time' => 'required|date_format:H:i',
                'to_time'   => 'required|date_format:H:i|after:from_time',
            ]);
        }
        $this->doValidate($request, $rule);
        $data = [];
        $uuid = Uuid::uuid();
        if ($request instanceof Request)
            $request = (object)$request->toArray();

        $period = CarbonPeriod::create($request->start, $request->end);
        foreach ($period as $date) {
            $dateString = $date->toDateString();
            if (!$request->is_all_day) {
                $start = new UTCDateTime(self::setTzUTCViaDateAndMinutes($dateString, $request->from_time,
                                                                         $request->timezone ?? null));
                $end   = new UTCDateTime(self::setTzUTCViaDateAndMinutes($dateString, $request->to_time,
                                                                         $request->timezone ?? null));
            }
            $data[] = [
                'class_id'         => $class->id ?? null,
                'term_id'          => $class->term_id ?? null,
                'repeat'           => $request->repeat ?? null,
                'type'             => CalendarTypeConstant::SCHEDULE,
                'is_all_day'       => $request->is_all_day ?? null,
                'start'            => $start ?? $dateString,
                'end'              => $end ?? $dateString,
                'timezone'         => $request->timezone ?? null,
                'location'         => $request->location ?? null,
                'group'            => $uuid,
                CodeConstant::UUID => SchoolServiceProvider::$currentSchool->uuid,
                'description'      => $request->description ?? null,
                'created_by'       => self::currentUser()?->id ?? null,
                'raw_data'         => $request
            ];
        }

        return $data;

    }

    /**
     * @param object        $request
     * @param ClassSQL|null $class
     *
     * @return array
     */
    private function _addClassScheduleIrregularly(object $request, ClassSQL $class = null): array
    {
        $rule = [
            'repeat_on' => 'required|array',
        ];
        if (!$request->is_all_day)
            $rule = array_merge($rule, [
                'repeat_on.*.start' => 'required|date_format:Y-m-d H:i',
                'repeat_on.*.end'   => 'required|date_format:Y-m-d H:i|after:repeat_on.*.start',
            ]);
        else
            $rule = array_merge($rule, [
                'repeat_on.*.start' => 'required|date_format:Y-m-d',
                'repeat_on.*.end'   => 'required|date_format:Y-m-d|after_or_equal:repeat_on.*.start',
            ]);
        $this->doValidate($request, $rule);

        $data = [];
        $uuid = Uuid::uuid();
        if ($request instanceof Request)
            $request = (object)$request->toArray();

        foreach ($request->repeat_on ?? [] as $repeat) {
            if (!$request->is_all_day) {
                $start = new UTCDateTime(self::setTzUTCViaDate($repeat['start'], $request->timezone ?? 'UTC'));
                $end   = new UTCDateTime(self::setTzUTCViaDate($repeat['end'], $request->timezone ?? 'UTC'));
            }
            $data[] = [
                'class_id'         => $class->id ?? null,
                'term_id'          => $class->term_id ?? null,
                'repeat'           => $request->repeat ?? null,
                'type'             => CalendarTypeConstant::SCHEDULE,
                'is_all_day'       => $request->is_all_day ?? null,
                'start'            => $start ?? $repeat['start'],
                'end'              => $end ?? $repeat['end'],
                'location'         => $request->location ?? null,
                'group'            => $uuid,
                'timezone'         => $request->timezone ?? null,
                'description'      => $request->description ?? null,
                CodeConstant::UUID => SchoolServiceProvider::$currentSchool->uuid,
                'created_by'       => self::currentUser()?->id ?? null,
                'raw_data'         => $request
            ];
        }

        return $data;
    }

    private function _deleteThisAndTheFollowingViaGroup(string $group, $start): void
    {
        CalendarNoSQL::whereGroup($group)
                     ->where('start', '>=', $start)
                     ->delete();
    }

    private function _deleteAllScheduleViaGroup(string $group)
    {
        CalendarNoSQL::whereGroup($group)->delete();
    }

    /**
     * @param int|string $id
     *
     * @return bool
     * @throws Throwable
     */
    public function delete(int|string $id): bool
    {
        $calendar = $this->get($id);
        if ($calendar->class_id) {
            $classAssignment = (new ClassAssignmentService)->getViaClassIdAndAssignment(
                $calendar->class_id,
                [ClassAssignmentConstant::PRIMARY_TEACHER, ClassAssignmentConstant::SECONDARY_TEACHER],
                self::currentUser()->id
            );

            if (!$this->isGod()
                && !$this->hasPermission(PermissionConstant::calendar(PermissionActionConstant::DELETE)) && $classAssignment)
                throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        } else {
            if (!$this->isGod() && !$this->hasPermission(PermissionConstant::calendar(PermissionActionConstant::DELETE)))
                throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }

        $this->preDelete($id);
        $request = (object)\request()->all();

        $this->doValidate($request, [
            'option' => 'required|in:This schedule,This and the following schedules,All schedules'
        ]);
        $calendar = $this->get($id);
        try {
            DB::beginTransaction();
            switch ($request->option) {
                case 'This and the following schedules' :
                    $this->_deleteThisAndTheFollowingViaGroup($calendar->group, $calendar->start);
                    break;
                case  'All schedules' :
                    $this->_deleteAllScheduleViaGroup($calendar->group);
                    break;
                default :
                    $calendar->delete();
            }
            $this->postDelete($id);
            DB::commit();

            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException(
                ['message' => __('can-not-del', ['attribute' => __('entity')]) . ": $id"],
                $e
            );
        }
    }

    /**
     * @Description Calculate process of class by id
     *
     * @Author      yaangvu
     * @Date        Sep 12, 2021
     *
     * @param int|string $classId
     *
     * @return array
     */
    #[ArrayShape(['total' => "int", 'done' => "int"])]
    public function getClassProcess(int|string $classId): array
    {
        $events   = $this->model->select('end')
                                ->where('class_id', '=', $classId)
                                ->get();
        $total    = $events->count();
        $finished = 0;

        foreach ($events as $event) {
            if (strtotime($event->end) < time())
                $finished++;
        }

        return [
            'total' => $total,
            'done'  => $finished
        ];
    }

    public function getCalendarViaClassId(int $classId): LengthAwarePaginator
    {
        (new AttendanceService())->checkPermissionAttendanceReport();
        $today       = Carbon::today();
        $currentTime = Carbon::now();

        try {
            return $this->model->where('class_id', '=', $classId)
                               ->where(function ($where) use ($today, $currentTime) {
                                   $where->orWhere('end', '<=', $currentTime);
                                   $where->orWhereDate('end', '<=', $today);
                               })
                               ->orderBy('end', 'DESC')
                               ->paginate(QueryHelper::limit());
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system - 500'), $e);
        }
    }

    /**
     * @Description
     *
     * @Author hoang
     * @Date   Apr 17, 2022
     *
     * @return bool
     */
    public function syncTerms(): bool
    {
        $data = ClassSQL::query()->pluck('term_id', 'id');

        foreach ($data as $key => $termId)
            CalendarNoSQL::query()->where('class_id', $key)
                         ->update(['term_id' => $termId]);

        return true;
    }

    public function addScheduleMeeting(object $request, $zoomMeeting, array $userUuids): bool
    {
        $startDate  = $request->calendar['start_date_time'];
        $dateString = Carbon::parse($startDate)->format("Y-m-d");
        $fromTime   = Carbon::parse($startDate)->format("H:i");
        $endDate    = Carbon::parse($startDate)->addMinutes($request->duration)->format("Y-m-d H:i");

        $startDateConvert = new UTCDateTime(self::setTzUTCViaDateAndMinutes($dateString, $fromTime,
                                                                            $request->timezone ?? null));

        $explodeEndDate = explode(' ', $endDate);
        $endDateConvert = new UTCDateTime(self::setTzUTCViaDateAndMinutes($explodeEndDate[0], $explodeEndDate[1],
                                                                          $request->timezone ?? null));
        $uuid           = Uuid::uuid();

        if ($request->class_id)
            $this->addScheduleMeetingTypeClass($request, $zoomMeeting, $userUuids, $startDateConvert, $endDateConvert,
                                               $uuid);
        else
            $this->addScheduleMeetingTypeUserInformation($request, $zoomMeeting, $userUuids, $startDateConvert,
                                                         $endDateConvert, $uuid);

        // $dataJob = $this->handleDataJobSendEmailToParticipant($request, $zoomMeeting, $startDate);
        //
        // $this->pushJobSendEmailToParticipant($zoomMeeting->id, $dataJob);

        return true;
    }

    public function addRecurringWithDailyMeeting(object $request, $zoomMeeting, array $userUuids): bool
    {
        $dataJob = [];

        $period = CarbonPeriod::create($request->calendar['start'], $request->calendar['end']);

        foreach ($period as $date) {
            if($date < Carbon::today())
                continue;
            $uuid       = Uuid::uuid();
            $dateString = $date->toDateString();

            $toTime = Carbon::parse($request->calendar['from_time'])->addMinutes($request->duration)->format('H:i');

            // convert date to iso
            $startDateConvert = new UTCDateTime(self::setTzUTCViaDateAndMinutes($dateString,
                                                                                $request->calendar['from_time'],
                                                                                $request->timezone ?? null));

            $endDateConvert = new UTCDateTime(self::setTzUTCViaDateAndMinutes($dateString, $toTime,
                                                                              $request->timezone ?? null));

            $currentTime = Carbon::now()->format('Y-m-d H:i:s');
            if ($currentTime > $startDateConvert->toDateTime()->format('Y-m-d H:i:s'))
                continue;
            if ($request->class_id)
                $this->addRecurringTypeClass($request, $startDateConvert, $endDateConvert, $zoomMeeting, $uuid,
                                             $userUuids);
            else
                $this->addRecurringTypeUserInformation($request, $startDateConvert, $endDateConvert, $zoomMeeting,
                                                       $uuid, $userUuids);

            // $startDate = $dateString . ' ' . $request->calendar['from_time'];
            // $dataJob[] = $this->handleDataJobSendEmailToParticipant($request, $zoomMeeting, $startDate);
        }

        //$this->pushJobSendEmailToParticipant($zoomMeeting->id, $dataJob);

        return true;
    }

    public function addRecurringWithWeeklyMeeting(object $request, $zoomMeeting, array $userUuids): bool
    {
        $period = CarbonPeriod::create($request->calendar['start'], $request->calendar['end']);

        $dataJob     = [];
        $dateOfWeeks = array_column($request->calendar['repeat_on'], 'day_of_week');

        // Iterate over the period
        foreach ($period as $date) {
            if($date < Carbon::today())
                continue;
            $uuid = Uuid::uuid();
            if (in_array($date->format("l"), $dateOfWeeks)) {
                $dateString = $date->toDateString();

                $fromTime      = $request->calendar['from_time'] ?? null;
                $startDate     = Carbon::parse($date)->format('Y-m-d');
                $startDateTime = $startDate . ' ' . $fromTime;
                $endDateTime   = Carbon::parse($startDateTime)->addMinutes($request->duration)
                                       ->format('Y-m-d H:i');

                // convert date to iso
                $startDateConvert = new UTCDateTime(self::setTzUTCViaDateAndMinutes($dateString,
                                                                                    trim($fromTime),
                                                                                    $request->timezone ?? null));

                $explodeEndDateTime = explode(' ', $endDateTime);
                $endDateConvert     = new UTCDateTime(self::setTzUTCViaDateAndMinutes($explodeEndDateTime[0],
                                                                                      trim($explodeEndDateTime[1]),
                                                                                      $request->timezone ?? null));

                $currentTime = Carbon::now()->format('Y-m-d H:i:s');
                if ($currentTime > $startDateConvert->toDateTime()->format('Y-m-d H:i:s'))
                    continue;

                if ($request->class_id)
                    $this->addRecurringTypeClass($request, $startDateConvert, $endDateConvert, $zoomMeeting, $uuid,
                                                 $userUuids);
                else
                    $this->addRecurringTypeUserInformation($request, $startDateConvert, $endDateConvert, $zoomMeeting,
                                                           $uuid, $userUuids);
                // handle data job send email to participant
                // $startDate = $dateString . ' ' . $request->calendar['from_time'];
                // $dataJob[] = $this->handleDataJobSendEmailToParticipant($request, $zoomMeeting, $startDate);
            }

        }

        // $this->pushJobSendEmailToParticipant($zoomMeeting->id, $dataJob);

        return true;
    }

    /**
     * @throws Throwable
     */
    public function deleteCalendarsTypeVideoConference(string $id, object $request): bool
    {
        $now      = Carbon::now();
        $calendar = CalendarNoSQL::query()->where('_id', $id)->first(); // get calendar is view display calendar

        if ($calendar->type != CalendarTypeConstant::VIDEO_CONFERENCE)
            throw new BadRequestException(
                ['message' => __("calendar.delete_calendar")], new Exception()
            );

        DB::beginTransaction();
        $calendarIds = CalendarNoSQL::query()->where('group', $calendar->group)->pluck('_id')
                                    ->toArray(); // get calendar when vcr has many class
        try {
            CalendarNoSQL::query()->whereIn('_id', $calendarIds)->delete();
            AttendanceSQL::query()->whereIn('calendar_id', $calendarIds)->delete();
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }

        // if ($calendar->start > $now)
        // {
        //     DB::beginTransaction();
        //     $calendarIds = CalendarNoSQL::query()->where('group', $calendar->group)->pluck('_id')->toArray(); // get calendar when vcr has many class
        //     try {
        //         CalendarNoSQL::query()->whereIn('_id', $calendarIds)->delete();
        //         AttendanceSQL::query()->whereIn('calendar_id', $calendarIds)->delete();
        //         DB::commit();
        //     }catch (Exception $e){
        //         DB::rollBack();
        //         throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        //     }
        // }
        // else
        //     throw new BadRequestException(
        //         ['message' => __("calendar.delete_calendar_past")], new Exception()
        //     );

        return true;
    }

    public function handleDataJobSendEmailToParticipant($request, $zoomMeeting, $startDate): array
    {
        // get user_uuid send email
        if ($request->type_guest == GuestObjectInviteToZoomConstant::USER_INFORMATION)
            $userUuid = $request->student_uuid;
        else
            $userUuid = ClassAssignmentSQL::query()
                                          ->join('users', 'users.id', 'class_assignments.user_id')
                                          ->whereIn('class_id', $request->class_id)
                                          ->pluck('users.uuid as user_uuid')
                                          ->toArray();

        // handle data job send email to participant

        $start             = Carbon::parse($startDate)->format('Y-m-d H:i A');
        $end               = Carbon::parse($startDate)->addMinutes($request->duration)->format('Y-m-d H:i A');
        $sendAt            = Carbon::parse($startDate)->subMinutes($request->participant_join_before_host)
                                   ->format('Y-m-d H:i');
        $dateAndTimeSendAt = explode(' ', $sendAt);

        return [
            "send_type"       => "email",
            "receiver_uuids"  => $userUuid,
            "css"             => [],
            "bcss"            => [],
            "title"           => "[" . SchoolServiceProvider::$currentSchool->name . "]" . " You're invited to Virtual meeting",
            "status"          => "Pending",
            "body"            => "When: " . $start . " - " . $end . "Join with link meet:" . $zoomMeeting->link_zoom,
            "html_enabled"    => true,
            "send_at"         => new UTCDateTime(self::setTzUTCViaDateAndMinutes($dateAndTimeSendAt[0],
                                                                                 $dateAndTimeSendAt[1])),
            "zoom_meeting_id" => $zoomMeeting->id
        ];
    }

    public function pushJobSendEmailToParticipant(int $zoomMeetingId, array $dataJob)
    {
        JobNoSQL::query()->where('zoom_meeting_id', $zoomMeetingId)->delete();

        JobNoSQL::query()->insert($dataJob ?? []);
    }

    public function getViaZoomMeetingIdAndDateAndTimezone(int    $zoomMeetingId, string $date = null,
                                                          string $timezone = null)
    {
        return CalendarNoSQL::query()->with('zoomMeeting.hostZoomMeeting.host')
                            ->where('zoom_meeting_id', $zoomMeetingId)
                            ->when($date, function ($q) use ($date, $timezone) {
                                $dateString = Carbon::parse($date)->format('Y-m-d');
                                $fromTime   = Carbon::parse($date)->format('H:i');
                                $dateTimeConvert
                                            = new UTCDateTime((new CalendarService())->setTzUTCViaDateAndMinutes($dateString,
                                                                                                                 $fromTime,
                                                                                                                 $timezone));
                                $q->whereDate('start', $dateTimeConvert);
                            })
                            ->first();
    }

    public function getCalendarZoomMeetingViaZoomMeetingId(int $zoomMeetingId, object $request)
    {
        $rules = [
            'date' => 'required|date_format:Y-m-d H:i'
        ];

        $this->doValidate($request, $rules);
        $zoomMeeting = ZoomMeetingSQL::query()->where('id', $zoomMeetingId)->first();
        if (!$zoomMeeting)
            throw new NotFoundException(
                ['message' => __("not-exist", ['attribute' => __('entity')]) . ": $zoomMeetingId"]);

        return $this->getViaZoomMeetingIdAndDateAndTimezone($zoomMeetingId, $request->date);
    }

    public function addScheduleMeetingTypeClass(object      $request, $zoomMeeting, array $userUuids,
                                                UTCDateTime $startTime, UTCDateTime $endTime, string $group): bool
    {
        $keyHostUuid = array_search($request->host_uuid, $userUuids);
        if ($keyHostUuid)
            unset($userUuids[$keyHostUuid]);

        $dateTimeAttendanceLog = Carbon::parse($startTime->toDateTime())->format("Y-m-d H:i:s");

        foreach ($request->class_id as $keyClassId => $classId) {
            $class                            = ClassSQL::query()->where('id', $classId)->first();
            $scheduleMeeting                  = new stdClass();
            $scheduleMeeting->name            = $request->title;
            $scheduleMeeting->type            = CalendarTypeConstant::VIDEO_CONFERENCE;
            $scheduleMeeting->status          = CalendarTypeConstant::FUTURE;
            $scheduleMeeting->timezone        = $request->timezone;
            $scheduleMeeting->is_all_day      = false;
            $scheduleMeeting->uuid            = SchoolServiceProvider::$currentSchool->uuid;
            $scheduleMeeting->zoom_meeting_id = $zoomMeeting->id;
            $scheduleMeeting->start           = $startTime;
            $scheduleMeeting->end             = $endTime;
            $scheduleMeeting->class_id        = $classId;
            $scheduleMeeting->is_view_calendar
                                              = count($request->class_id) == 1 ? null : ($keyClassId != 0 ? false : null);
            $scheduleMeeting->group           = $group;
            $scheduleMeeting->term_id         = $class->term_id;
            $this->createModel();
            $calendar = $this->add($scheduleMeeting);

            // insert attendance log

            $users = ClassAssignmentSQL::query()
                                       ->join('users', 'users.id', 'class_assignments.user_id')
                                       ->where('class_id', $classId)
                                       ->where('class_assignments.assignment', ClassAssignmentConstant::STUDENT)
                                       ->whereIn('users.uuid', $userUuids)
                                       ->select('users.uuid as user_uuid', 'class_assignments.class_id',
                                                'user_id as user_id')->get()
                                       ->toArray();

            (new AttendanceService())->insertParticipantToAttendance($users, $startTime, $endTime, $calendar->_id,
                                                                     $zoomMeeting->id, $dateTimeAttendanceLog);

        }

        return true;
    }

    public function addScheduleMeetingTypeUserInformation(object      $request, $zoomMeeting, array $userUuids,
                                                          UTCDateTime $startTime, UTCDateTime $endTime,
                                                          string      $group): bool
    {
        $scheduleMeeting                  = new stdClass();
        $scheduleMeeting->name            = $request->title;
        $scheduleMeeting->type            = CalendarTypeConstant::VIDEO_CONFERENCE;
        $scheduleMeeting->status          = CalendarTypeConstant::FUTURE;
        $scheduleMeeting->timezone        = $request->timezone;
        $scheduleMeeting->is_all_day      = false;
        $scheduleMeeting->uuid            = SchoolServiceProvider::$currentSchool->uuid;
        $scheduleMeeting->zoom_meeting_id = $zoomMeeting->id;
        $scheduleMeeting->start           = $startTime;
        $scheduleMeeting->end             = $endTime;
        $scheduleMeeting->group           = $group;

        $calendar = $this->add($scheduleMeeting);

        // insert attendance log
        $keyHostUuid = array_search($request->host_uuid, $userUuids);
        if ($keyHostUuid)
            unset($userUuids[$keyHostUuid]);


        $dateTimeAttendanceLog = Carbon::parse($startTime->toDateTime())->format("Y-m-d H:i:s");

        // insert attendance log

        $users = UserSQL::query()->whereIn('uuid', $userUuids)
                        ->select('users.uuid as user_uuid', 'users.id as user_id')
                        ->get()
                        ->toArray();

        (new AttendanceService())->insertParticipantToAttendance($users, $startTime, $endTime, $calendar->_id,
                                                                 $zoomMeeting->id, $dateTimeAttendanceLog);

        return true;
    }

    public function addRecurringTypeClass(object $request, UTCDateTime $startDateConvert, UTCDateTime $endDateConvert,
                                                 $zoomMeeting, $uuid, array $userUuids)
    {
        foreach ($request->class_id as $keyClasId => $classId) {
            $class        = ClassSQL::query()->where('id', $classId)->first();
            $dataCalendar = [
                'name'             => $request->title,
                'repeat'           => $request->calendar['repeat'] ?? null,
                'type'             => CalendarTypeConstant::VIDEO_CONFERENCE,
                'status'           => CalendarTypeConstant::FUTURE,
                'start'            => $startDateConvert,
                'end'              => $endDateConvert,
                'from_time'        => $request->calendar['from_time'] ?? "",
                'timezone'         => $request->timezone ?? null,
                'group'            => $uuid,
                CodeConstant::UUID => SchoolServiceProvider::$currentSchool->uuid,
                'created_by'       => self::currentUser()?->id ?? null,
                'zoom_meeting_id'  => $zoomMeeting->id,
                'class_id'         => $classId,
                'is_view_calendar' => count($request->class_id) == 1 ? null : ($keyClasId != 0 ? false : null),
                'term_id'          => $class->term_id,
                'raw_data'         => $request->calendar
            ];

            $this->createModel();
            $scheduleMeeting = $this->add((object)$dataCalendar);

            $dateTimeAttendanceLog = Carbon::parse($startDateConvert->toDateTime())
                                           ->format("Y-m-d H:i:s");

            $users = ClassAssignmentSQL::query()
                                       ->join('users', 'users.id', 'class_assignments.user_id')
                                       ->where('class_id', $classId)
                                       ->where('class_assignments.assignment', ClassAssignmentConstant::STUDENT)
                                       ->whereIn('users.uuid', $userUuids)
                                       ->select('users.id as user_id', 'users.uuid as user_uuid',
                                                'class_assignments.class_id')
                                       ->get()
                                       ->toArray();


            // insert attendance
            (new AttendanceService())->insertParticipantToAttendance($users, $startDateConvert, $endDateConvert,
                                                                     $scheduleMeeting->_id, $zoomMeeting->id,
                                                                     $dateTimeAttendanceLog);
        }
    }

    public function addRecurringTypeUserInformation(object      $request, UTCDateTime $startDateConvert,
                                                    UTCDateTime $endDateConvert,
                                                                $zoomMeeting, $uuid,
                                                    array       $userUuids)
    {
        $dataCalendar = [
            'name'             => $request->title,
            'repeat'           => $request->calendar['repeat'] ?? null,
            'type'             => CalendarTypeConstant::VIDEO_CONFERENCE,
            'status'           => CalendarTypeConstant::FUTURE,
            'start'            => $startDateConvert,
            'end'              => $endDateConvert,
            'from_time'        => $request->calendar['from_time'] ?? "",
            'timezone'         => $request->timezone ?? null,
            'group'            => $uuid,
            CodeConstant::UUID => SchoolServiceProvider::$currentSchool->uuid,
            'created_by'       => self::currentUser()?->id ?? null,
            'zoom_meeting_id'  => $zoomMeeting->id,
            'raw_data'         => $request->calendar
        ];

        $this->createModel();
        $scheduleMeeting = $this->add((object)$dataCalendar);

        $dateTimeAttendanceLog = Carbon::parse($startDateConvert->toDateTime())
                                       ->format("Y-m-d H:i:s");

        $users = UserSql::query()->whereIn('uuid', $userUuids)
                        ->select('id as user_id', 'uuid as user_uuid')
                        ->get()
                        ->toArray();
        // insert attendance
        (new AttendanceService())->insertParticipantToAttendance($users, $startDateConvert, $endDateConvert,
                                                                 $scheduleMeeting->_id, $zoomMeeting->id,
                                                                 $dateTimeAttendanceLog);
    }

    public function getCalendarVcrByClassId(int $classId): Collection|array
    {
        return CalendarNoSQL::query()->with('zoomMeeting')
                            ->where('start', '>=', Carbon::now())
                            ->where('class_id', $classId)
                            ->where('type', CalendarTypeConstant::VIDEO_CONFERENCE)
                            ->where('status', CalendarTypeConstant::FUTURE)
                            ->get();
    }

    /**
     * @throws Throwable
     */
    public function cancelCalendarsTypeVideoConference(string $id, object $request): bool
    {
        $calendar = CalendarNoSQL::query()->where('_id', $id)->first(); // get calendar is view display calendar

        if ($calendar->type != CalendarTypeConstant::VIDEO_CONFERENCE)
            throw new BadRequestException(
                ['message' => __("calendar.cancel_calendar")], new Exception()
            );

        DB::beginTransaction();
        $calendarIds = CalendarNoSQL::query()->where('group', $calendar->group)->pluck('_id')
                                    ->toArray(); // get calendar when vcr has many class

        try {
            CalendarNoSQL::query()->whereIn('_id', $calendarIds)->update(['type' => CalendarTypeConstant::CANCELED]);
            AttendanceSQL::query()->whereIn('calendar_id', $calendarIds)->delete();
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }

        return true;
    }

    public function deleteCalendarsTypeCanceled(string $id): bool
    {
        $calendar = CalendarNoSQL::query()->where('_id', $id)->first(); // get calendar is view display calendar

        if ($calendar->type != CalendarTypeConstant::CANCELED)
            throw new BadRequestException(
                ['message' => __("calendar.cancel_calendar")], new Exception()
            );

        $calendarIds = CalendarNoSQL::query()->where('group', $calendar->group)->pluck('_id')
                                    ->toArray();

        CalendarNoSQL::query()->whereIn('_id', $calendarIds)->delete();
        return true;
    }
}
