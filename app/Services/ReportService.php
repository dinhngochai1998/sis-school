<?php
/**
 * @Author yaangvu
 * @Date   Aug 11, 2021
 */

namespace App\Services;

use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use JetBrains\PhpStorm\ArrayShape;
use stdClass;
use YaangVu\Constant\AttendanceConstant;
use YaangVu\Constant\ClassAssignmentConstant;
use YaangVu\Constant\CommunicationLogConstant;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Constant\StatusConstant;
use YaangVu\Constant\SubjectConstant;
use YaangVu\Constant\TaskManagementConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Helpers\QueryHelper;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Constants\CalendarTypeConstant;
use YaangVu\SisModel\App\Models\impl\AttendanceSQL;
use YaangVu\SisModel\App\Models\impl\CalendarNoSQL;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\impl\CommunicationLogNoSql;
use YaangVu\SisModel\App\Models\impl\CpaSQL;
use YaangVu\SisModel\App\Models\impl\GpaSQL;
use YaangVu\SisModel\App\Models\impl\SubTaskSQL;
use YaangVu\SisModel\App\Models\impl\TaskStatusSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Models\impl\UserSQL;
use YaangVu\SisModel\App\Models\TaskStatus;
use YaangVu\SisModel\App\Providers\RoleServiceProvider;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class ReportService
{
    protected const MAX_CPA_BONUS_POINTS = 4.80;
    use RoleAndPermissionTrait;

    public AttendanceService      $attendanceService;
    public Builder|EBuilder|Model $gpa;
    protected string              $attendancePresentView = 'attendance_present_view';
    protected string              $scoreView             = 'score_view';
    protected array               $attendances
                                                         = [
            'labels'    => [],
            'data'      => [],
            'data_raw'  => [],
            'attend'    => [],
            'absence'   => [],
            'data_max'  => null,
            'data_min'  => null,
            'label_max' => null,
            'label_min' => null,
            'data_avg'  => null,
        ];

    public function __construct()
    {
        $this->gpa = new GpaSQL();
    }

    /**
     * @Author yaangvu
     * @Date   Aug 11, 2021
     * @Status Todo
     *
     * @param object $request
     *
     * @return array
     */

    #[ArrayShape(['labels' => "string[]", 'data' => "float[]", 'label_max' => "string", 'data_max' => "float", 'label_min' => "string", 'data_min' => "float", 'data_avg' => "float"])]
    function getAttendancePercentage(object $request): array
    {
        $studentId = ($request->student_id ?? null) ?: null;
        $programId = ($request->program_id ?? null) ?: null;
        $termId    = ($request->term_id ?? null) ?: null;
        $classIds  = ($request->class_ids ?? []) ?: [];

        // Get attends & absences data
        if ($termId) {
            [$attends, $absences] = $this->_getAttendancePercentageForSpecificTermId($studentId, $programId, $termId,
                                                                                     $classIds);
        } else {
            [$attends, $absences] = $this->_getAttendancePercentageForAllTerm($studentId, $programId, $classIds);
        }
        // Cast $attends & $absences to Collection
        [$attends, $absences] = [collect($attends), collect($absences)];

        // If you have not found any data
        if ($attends->isEmpty() && $absences->isEmpty())
            return $this->attendances;

        // Map $attends data into $this->attendances
        $attends->map(function ($attend) {
            if (!in_array($attend->time, $this->attendances['labels'])) {
                $this->attendances['labels'][] = $attend->time;
            }
            $key        = array_search($attend->time, $this->attendances['labels']);
            $attendRaw  = $this->attendances['data_raw'][$key] = $this->attendances['attend'][$key] = $attend->attend;
            $absenceRaw = $this->attendances['absence'][$key] = $this->attendances['absence'][$key] ?? 0;

            $this->attendances['data'][$key] = 100 * $attendRaw / ($attendRaw + $absenceRaw);

        });

        // Map $absence data into $this->attendances
        $absences->map(function ($absence) {
            if (!in_array($absence->time, $this->attendances['labels'])) {
                $this->attendances['labels'][] = $absence->time;
            }
            $key        = array_search($absence->time, $this->attendances['labels']);
            $attendRaw  = $this->attendances['data_raw'][$key] = $this->attendances['attend'][$key]
                = $this->attendances['attend'][$key] ?? 0;
            $absenceRaw = $this->attendances['absence'][$key] = $absence->absence;

            $this->attendances['data'][$key] = 100 * $attendRaw / ($attendRaw + $absenceRaw);

        });

        // Calculate $data_max, $data_min, $data_avg
        $dataCollection                = collect($this->attendances['data']);
        $this->attendances['data_max'] = $dataCollection->max();
        $this->attendances['data_min'] = $dataCollection->min();
        $this->attendances['data_avg'] = $dataCollection->average();

        // Calculate label of {$data_max, $data_min, $data_avg}
        $labelKeyMax                    = array_search($this->attendances['data_max'], $this->attendances['data']);
        $labelKeyMin                    = array_search($this->attendances['data_min'], $this->attendances['data']);
        $this->attendances['label_max'] = $this->attendances['labels'][$labelKeyMax];
        $this->attendances['label_min'] = $this->attendances['labels'][$labelKeyMin];

        return $this->attendances;
    }

    private function _getAttendancePercentageForSpecificTermId(null|int|string $studentId, null|int|string $programId,
                                                               null|int|string $termId, ?array $classIds): array
    {
        $attends = $this->_queryBuilderAttendancePresentView($studentId, $programId, $termId, $classIds)
                        ->selectRaw("concat(date_part('year', start), '-', date_part('month', start)) as time,
                                    count(*) as attend")
                        ->where('group', '=', 'attend')
                        ->groupBy('time')
                        ->orderBy('time')
                        ->get();

        $absences = $this->_queryBuilderAttendancePresentView($studentId, $programId, $termId, $classIds)
                         ->selectRaw("concat(date_part('year', start), '-', date_part('month', start)) as time,
                                    count(*) as absence")
                         ->where('group', '=', 'absence')
                         ->groupBy('time')
                         ->orderBy('time')
                         ->get();

        return [$attends, $absences];
    }

    /**
     * @Author yaangvu
     * @Date   Aug 17, 2021
     *
     * @param string|int|null $student_id
     * @param int|null        $program_id
     * @param int|null        $termId
     * @param array|null      $classIds
     *
     * @return EBuilder|Builder
     */
    private function _queryBuilderAttendancePresentView(null|string|int $student_id, ?int $program_id,
                                                        ?int            $termId, ?array $classIds): EBuilder|Builder
    {
        return DB::table($this->attendancePresentView)
                 ->when($termId, function (EBuilder|Builder $query) use ($termId) {
                     return $query->where('term_id', '=', $termId);
                 })
                 ->when($classIds, function (EBuilder|Builder $query) use ($classIds) {
                     return $query->whereIn('class_id', $classIds);
                 })
                 ->when($student_id, function (EBuilder|Builder $query) use ($student_id) {
                     $userSqlId = (new UserService())->get($student_id)->userSql?->id;

                     return $query->where('user_id', '=', $userSqlId);
                 })
                 ->when($program_id, function (EBuilder|Builder $query) use ($program_id) {
                     return $query->where('program_id', '=', $program_id);
                 });
    }

    private function _getAttendancePercentageForAllTerm(null|int|string $studentId, null|int|string $programId,
                                                        ?array          $classIds): array
    {
        $attends = $this->_queryBuilderAttendancePresentView($studentId, $programId, null, $classIds)
                        ->selectRaw("date_part('year', start) AS time,
                                    count(*) as attend")
                        ->where('group', '=', 'attend')
                        ->groupBy('time')
                        ->orderBy('time')
                        ->get();

        $absences = $this->_queryBuilderAttendancePresentView($studentId, $programId, null, $classIds)
                         ->selectRaw("date_part('year', start) AS time,
                                    count(*) as absence")
                         ->where('group', '=', 'absence')
                         ->groupBy('time')
                         ->orderBy('time')
                         ->get();

        return [$attends, $absences];
    }

    /**
     * @Author yaangvu
     * @Date   Aug 11, 2021
     * @Status Done
     *
     * @return int[]
     */
    #[ArrayShape(['total_students' => "int", 'total_classes' => "int", 'attend_percentage' => "float", 'absence_percentage' => "float"])]
    public function getAttendanceSummary(object $request): array
    {
        $termId   = ($request->term_id ?? null) ?: null;
        $classIds = ($request->class_ids ?? []) ?: [];

        $attendCond = [
            'condition' => 'group',
            'value'     => AttendanceConstant::ATTEND,
        ];

        $absenceCond = [
            'condition' => 'group',
            'value'     => AttendanceConstant::ABSENCE,
        ];

        $attendCount         = $this->_calculateAttendanceSummaryData($termId, $classIds, 'group', $attendCond);
        $absenceCount        = $this->_calculateAttendanceSummaryData($termId, $classIds, 'group', $absenceCond);
        $sumAttendAndAbsence = $attendCount + $absenceCount;

        return [
            'total_students'     => (new ClassAssignmentService())
                ->countStudentByTermIdAndClassIds($termId, $classIds, ClassAssignmentConstant::STUDENT),
            'total_classes'      => (new ClassService())->countByTermIdAndClassIds($termId, $classIds),
            'attend_percentage'  => $sumAttendAndAbsence == 0 ? 0 : ($attendCount * 100) / $sumAttendAndAbsence,
            'absence_percentage' => $sumAttendAndAbsence == 0 ? 0 : ($absenceCount * 100) / $sumAttendAndAbsence,
        ];
    }

    /**
     * @Author yaangvu
     * @Date   Aug 12, 2021
     * @Status Done
     *
     * @param int|null   $termId
     * @param array|null $classIds
     * @param string     $groupBy
     * @param array|null $whereCondition
     *
     * @return int
     */
    private function _calculateAttendanceSummaryData(?int   $termId, ?array $classIds,
                                                     string $groupBy, ?array $whereCondition = []): int
    {
        $query = DB::table($this->attendancePresentView)
                   ->when($termId, function ($query) use ($termId) {
                       return $query->where('term_id', '=', $termId);
                   })
                   ->when($classIds, function ($query) use ($classIds) {
                       return $query->whereIn('class_id', $classIds);
                   })
                   ->when($whereCondition, function ($query) use ($whereCondition) {
                       return $query->where($whereCondition['condition'],
                                            $whereCondition['operator'] ?? '=',
                                            $whereCondition['value']);
                   })
                   ->groupBy($groupBy);

        if ($whereCondition) {
            return $query->count();
        } else
            return $query->select($groupBy)->get()->count();
    }

    /**
     * Get list students in top gpa
     *
     * @Author yaangvu
     * @Date   Aug 15, 2021
     * @Status Doing
     *
     * @param object $request
     *
     * @return LengthAwarePaginator
     */
    function getTopGpaStudents(object $request): LengthAwarePaginator
    {
        $rules = [
            'grade_id' => 'required|exists:grades,id'
        ];
        BaseService::doValidate($request, $rules);

        $limit     = $request->limit ?? 5;
        $orderType = $request->order_type ?? 'top';
        $rank      = $request->rank ?? 5;
        $termId    = ($request->term_id ?? null) ?: null;
        $gradeId   = $request->grade_id;

        if ($termId === null)
            $this->gpa = new CpaSQL();

        $topRanks = $this->_getTopGpaRanks($termId, $gradeId, $orderType, $rank);

        $gpa = $this->gpa
            ->with('user.userNoSql')
            ->where('grade_id', '=', $gradeId)
            ->where('school_id', '=', SchoolServiceProvider::$currentSchool->id)
            ->when($termId, function (EBuilder|GpaSQL $query) use ($termId) {
                return $query->whereTermId($termId);
            })
            // ->where('term_id', '=', $termId)
            ->whereIn('rank', $topRanks)
            ->orderBy('rank', strtolower($orderType) === 'bottom' ? 'desc' : 'asc')
            ->paginate($limit);

        // Handle data items to response
        $gpa->setCollection($this->_handleTopGpaData($gpa->items()));

        return $gpa;
    }

    /**
     * @Author yaangvu
     * @Date   Aug 15, 2021
     *
     * @param int|null    $termId
     * @param int|null    $gradeId
     * @param string|null $orderType
     * @param int|null    $limit
     *
     * @return array|Collection
     */
    private function _getTopGpaRanks(?int    $termId = null, int $gradeId = null,
                                     ?string $orderType = 'top', ?int $limit = 5): array|Collection
    {
        return $this->gpa
            ->whereGradeId($gradeId)
            ->whereSchoolId(SchoolServiceProvider::$currentSchool->id)
            ->when($termId, function (EBuilder|GpaSQL $query) use ($termId) {
                return $query->whereTermId($termId);
            })
            ->groupBy('rank')
            ->orderBy('rank', strtolower($orderType) === 'bottom' ? 'desc' : 'asc')
            ->limit($limit)
            ->pluck('rank');
    }

    /**
     * @Author yaangvu
     * @Date   Aug 15, 2021
     *
     * @param array $items
     *
     * @return Collection
     */
    private function _handleTopGpaData(array $items): Collection
    {
        $data = collect();
        foreach ($items as $item) {
            $data->push(
                [
                    'student_code' => $item->user->userNoSql->student_code ?? null,
                    'avatar'       => $item->user->userNoSql->avatar ?? null,
                    'full_name'    => $item->user->userNoSql->full_name ?? null,
                    'rank'         => $item->rank,
                    'study_status' => $item->user->userNoSql->study_status ?? StatusConstant::STUDYING,
                    'gpa'          => $item->gpa ?? $item->cpa ?? null,
                    'term_id'      => $item->term_id ?? null,
                    'grade_id'     => $item->grade_id ?? null,
                ]
            );
        }

        return $data;
    }

    /**
     * @Author KeyHoang
     *
     * @param object $request
     *
     * @return array
     */
    public function getChartPipeAttendance(object $request): array
    {
        $studentId = ($request->student_id ?? null) ?: null;
        $programId = ($request->program_id ?? null) ?: null;
        $termId    = ($request->term_id ?? null) ?: null;
        $classIds  = ($request->class_ids ?? null) ?: null;

        $attendances = $this->_queryBuilderAttendancePresentView($studentId, $programId, $termId, $classIds)
                            ->selectRaw('status,COUNT(*) as count')
                            ->groupBy('status')
                            ->get();

        $totalAttendances = $this->_queryBuilderAttendancePresentView($studentId, $programId, $termId, $classIds)
                                 ->count();
        foreach ($attendances as $attendance) {
            $response['labels'][] = $attendance->status;
            $response['data'][]   = (($attendance->count ?? 0) / $totalAttendances) * 100;
        }

        return $response ?? ['labels' => [], 'data' => []];
    }

    /**
     * @Author KeyHoang
     *
     * @param string $studentId
     * @param object $request
     *
     * @return Model|EBuilder|null
     */
    function getStudentForAttendanceReport(string $studentId, object $request): Model|Builder|null
    {
        $programId = ($request->program_id ?? null) ?: null;
        $termId    = ($request->term_id ?? null) ?: null;
        $classIds  = ($request->class_ids ?? null) ?: null;

        $userNoSql = (new UserService())->get($studentId);
        $userSql   = $userNoSql?->userSql;

        return UserSQL::whereId($userSql->id ?? null)
                      ->with([
                                 'userNoSql',
                                 'classes'             => function ($query) use (
                                     $programId, $termId, $classIds
                                 ) {
                                     $query->when($termId, function ($q) use ($termId) {
                                         $q->where('classes.term_id', $termId);
                                     });
                                     $query->when($classIds, function ($q) use ($classIds) {
                                         $q->whereIn('classes.id', $classIds);
                                     });
                                     $query->when($programId, function ($q) use ($programId) {
                                         $q->join('attendances', 'attendances.class_id', '=', 'classes.id');
                                         $q->join('users', 'users.id', '=', 'attendances.user_id');
                                         $q->join('user_program', 'user_program.user_id', '=', 'users.id');
                                         $q->join('programs', 'programs.id', '=', 'user_program.program_id');
                                         $q->where('programs.id', $programId);
                                     });
                                     $query->groupBy(['classes.id', 'class_assignments.user_id', 'class_assignments.class_id']);
                                 },
                                 'classes.teachers'    => function ($q) {
                                     $q->where('assignment', ClassAssignmentConstant::PRIMARY_TEACHER);
                                 },
                                 'classes.teachers.users',
                                 'classes.subject',
                                 'classes.attendances' => function ($query) use ($userSql) {
                                     $query->selectRaw('attendances.status, COUNT(attendances.status) as count , attendances.class_id');
                                     $query->where('attendances.user_id', $userSql->id ?? null);
                                     $query->groupBy(['attendances.status', 'attendances.class_id']);
                                 },
                             ])->first();
    }

    /**
     * @Author KeyHoang
     *
     * @param object $request
     *
     * @return Collection|array
     */
    function getAttendanceTopPresent(object $request): Collection|array
    {
        $limit    = $request->limit ?? 10;
        $classIds = !empty($request->class_ids) ?
            $request->class_ids : ClassSQL::whereTermId($request->term_id)->pluck('id');

        $queryTotalPresent = AttendanceSQL::whereStatus(AttendanceConstant::PRESENT)
                                          ->selectRaw('status, count(*) AS count_present')
                                          ->whereIn('class_id', $classIds)
                                          ->groupBy('status');

        return AttendanceSQL::with('user.userNoSql')
                            ->from('attendances as a')
                            ->where('a.status', AttendanceConstant::PRESENT)
                            ->leftJoinSub($queryTotalPresent, 'tp', 'tp.status', '=', 'a.status')
                            ->selectRaw('a.user_id , (count(a.*) * 100.0)/ tp.count_present  AS percent')
                            ->whereIn('a.class_id', $classIds)
                            ->groupBy(['a.user_id', 'tp.count_present'])
                            ->orderByDesc('percent')
                            ->limit($limit)
                            ->get();
    }

    /**
     *
     * @param object $request
     *
     * @return object
     */
    public function getGpaSummary(object $request): object
    {
        $studentId  = ($request->student_id ?? null) ?: null;
        $programId  = ($request->program_id ?? null) ?: null;
        $schoolId   = SchoolServiceProvider::$currentSchool?->id;
        $student_id = (new UserService())->get($studentId)->userSql?->id ?? null;
        $cpaScores  = (new CpaService())->getCurrentCpa($student_id, $programId, $schoolId)->first();

        $cpaStudent    = new stdClass();
        $bonusCpa      = $cpaScores->bonus_cpa ?? null;
        $cpaBonusPoint = $bonusCpa + $cpaScores?->cpa;
        if ($cpaBonusPoint >= self::MAX_CPA_BONUS_POINTS) {
            $cpaBonusPoint = self::MAX_CPA_BONUS_POINTS;
        }
        $cpaStudent->total_cpa             = number_format($cpaScores?->cpa, 2, ',', '.');
        $cpaStudent->total_cpa_bonus_point = number_format($cpaBonusPoint, 2, ',', '.');
        $cpaStudent->total_earned_credits  = $cpaScores?->earned_credit ?? 0;
        $cpaStudent->current_rank          = $cpaScores?->rank ?? 0;

        return $cpaStudent;
    }

    /**
     *
     * @param object $request
     *
     * @return object|array
     */
    public function getScoreSummary(object $request): object|array
    {
        $termId        = ($request->term_id ?? null) ?: null;
        $gradeId       = ($request->grade_id ?? null) ?: null;
        $schoolId      = SchoolServiceProvider::$currentSchool->id;
        $scoresSummary = $this->gpa->when($termId, function ($query) use ($termId) {
            return $query->where('term_id', '=', $termId);
        })->when($gradeId, function ($query) use ($gradeId) {
            return $query->where('grade_id', '=', $gradeId);
        })->where('school_id', '=', $schoolId)->get();

        $totalStudents = $scoresSummary->count();
        if (empty($totalStudents)) {
            return [];
        }
        $totalScores = 0;
        foreach ($scoresSummary as $score) {
            $totalScores += $termId ? $score?->gpa : $score?->cpa;
        }

        $averageScores                = number_format($totalScores / $totalStudents, 2, ',', '.');
        $scoreSummary                 = new stdClass();
        $scoreSummary->total_students = $totalStudents;
        $scoreSummary->average        = $averageScores;

        return $scoreSummary;
    }

    /**
     *
     * @param object $request
     *
     * @return array
     */
    #[ArrayShape(['labels' => "string[]"])]
    public function getScoreGradeLetter(object $request): array
    {
        // $termId    = ($request->term_id ?? null) ?: null;
        // $studentId = $request->student_id ?
        //     ((new UserService())->get($request->student_id)?->userSql->id ?? null) : null;
        // $programId = $request->program_id ?? null;
        // $gradeId   = $request->grade_id ?? null;
        //
        // $userUuid = BaseService::currentUser()?->uuid ?? null;
        // $user     = (new UserService())->getByUuid($userUuid);
        // if ($this->hasAnyRole(RoleConstant::STUDENT) && $this->isMe($user)) {
        //     $studentId = BaseService::currentUser()?->id ?? null;
        // }

        if (!$this->hasPermission(PermissionConstant::scoreReport(PermissionActionConstant::VIEW)))
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());

        $studentId = ($request->student_id ?? null) ?: null;
        if ($studentId)
            $studentId = (new UserService())->get($request->student_id)?->userSql?->id;

        if ($this->hasAnyRole(RoleConstant::STUDENT))
            $studentId = BaseService::currentUser()->id;

        // if ($this->hasAnyRole(RoleConstant::ADMIN) || $this->hasAnyRole(RoleConstant::FAMILY)) {
        //     $studentId = ($request->student_id ?? null) ?: null;
        //     if ($studentId)
        //         $studentId = (new UserService())->get($request->student_id)?->userSql?->id;
        // } elseif ($this->hasAnyRole(RoleConstant::STUDENT) && $this->isMe(BaseService::currentUser()))
        //     $studentId = BaseService::currentUser()->id;
        // else
        //     throw new ForbiddenException("You don't have permission to do this", new Exception());

        $termId    = ($request->term_id ?? null) ?: null;
        $programId = ($request->program_id ?? null) ?: null;
        $gradeId   = ($request->grade_id ?? null) ?: null;

        if ($studentId || $programId)
            $gradeLetterSummary = $this->_calculatePercentageGradeLetter($studentId, $programId, $termId, 'class_id');
        else
            $gradeLetterSummary = $this->_calculatePercentageGradeLetter($studentId, $programId, $termId, 'user_id');

        if ($gradeId) {
            $gradeName = (new GradeService())->get($gradeId)?->name;
            $userIds   = [];
            if ($gradeLetterSummary) {
                foreach ($gradeLetterSummary as $index => $info) {
                    $userIds[] = $info->user_id ?? null;
                }
                $listUserId = $this->_checkGradeUsers($userIds, $gradeName);
                foreach ($gradeLetterSummary as $key => $info) {
                    if (empty($info->user_id)) continue;
                    if (!in_array($info->user_id, $listUserId)) {
                        unset($gradeLetterSummary[$key]);
                    }
                }
            }
        }

        $gradeLetter = $gradeLetterSummary->groupBy('grade_letter')->toArray();
        ksort($gradeLetter);
        $response = [
            'labels' => [],
            'data'   => []
        ];
        foreach ($gradeLetter as $group => $info) {
            if (empty($group)) continue;
            $response['labels'][] = strtoupper($group);
            $response['data'][]   = count($info);
        }

        return $response;
    }

    /**
     * @Author     : RickyTzu
     * @Date       : Sep 17, 2021
     * @Description:
     *
     * @param string|int|null $student_id
     * @param int|null        $program_id
     * @param int|null        $termId
     * @param string          $groupBy
     *
     * @return Collection
     */
    private function _calculatePercentageGradeLetter(null|string|int $student_id, ?int $program_id,
                                                     ?int            $termId, string $groupBy): Collection
    {
        return DB::table($this->scoreView)
                 ->when($student_id, function (EBuilder|Builder $query) use ($student_id) {
                     return $query->where('user_id', '=', $student_id);
                 })
                 ->when($termId, function (EBuilder|Builder $query) use ($termId) {
                     return $query->where('term_id', $termId);
                 })
                 ->when($program_id, function (EBuilder|Builder $query) use ($program_id) {
                     return $query->where('program_id', $program_id);
                 })
                 ->where('status', '=', StatusConstant::CONCLUDED)
                 ->selectRaw($groupBy . ' ,grade_letter, COUNT(*) as count')
                 ->groupBy('grade_letter', $groupBy)
                 ->get();
    }

    /**
     * @Author     : RickyTzu
     * @Date       : Sep 17, 2021
     * @Description:
     *
     * @param array|null      $userIds
     * @param int|string|null $grade
     *
     * @return array
     */
    private function _checkGradeUsers(?array $userIds, int|string|null $grade): array
    {
        if (!$userIds)
            return [];

        try {
            $students = UserSQL::whereIn('id', $userIds)->with([
                                                                   'userNoSql' => function ($query) use (
                                                                       $grade
                                                                   ) {
                                                                       $query->where('grade', $grade);
                                                                   }
                                                               ])->get();
            $usersId  = [];
            foreach ($students as $student) {
                if (!$student->userNoSql) continue;
                $usersId[] = $student->id;
            }

            return $usersId;
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     *
     * @param object $request
     *
     * @return array
     */
    public function getScoreCourseGrade(object $request): array
    {
        $studentId = ($request->student_id ?? null) ?: null;
        $programId = ($request->program_id ?? null) ?: null;
        $termId    = ($request->term_id ?? null) ?: null;

        $user = $studentId ? (new UserService())->get($studentId) : null;

        $scores            = $this->getScoresByUserIdAndSchoolIdAndProgramIdAndTermId($user?->userSql['id'],
                                                                                      SchoolServiceProvider::$currentSchool->id,
                                                                                      $programId, $termId);
        $scoreCourseGrades = [];
        foreach ($scores->groupBy('term_name') as $termName => $term) {
            foreach ($term as $id => $class) {
                if ($class->subject_type == SubjectConstant::HONORS) {
                    $class->bonus_point = $class->extra_point_honor;
                } elseif ($class->subject_type == SubjectConstant::ADVANCEDPLACEMENT) {
                    $class->bonus_point = $class->extra_point_advanced;
                } else {
                    $class->bonus_point = 0;
                }

                $class->final_score = $class?->score ?? 0;
                $term[$id]          = $class;
            }

            $gpa         = $term->sum(function ($term) {
                if ($term->is_calculate_gpa)
                    if ($term->real_weight == 0)
                        return $term->point * $term->weight;
                    else
                        return $term->point * $term->real_weight;
            });
            $totalWeight = $term->sum(function ($term) {
                if ($term->is_calculate_gpa)
                    if ($term->real_weight == 0)
                        return $term->weight;
                    else
                        return $term->real_weight;
            });

            $scoreCourseGrades[] = [
                'term_name' => $termName,
                'classes'   => $term,
                "total"     => [
                    'earned_gpa'     => round($gpa / ($totalWeight == 0 ? 1 : $totalWeight), 2),
                    'earned_credits' => $term->sum(function ($term) {
                        if ($term->is_pass) {
                            return $term->credits;
                        }
                    })
                ]
            ];
        }

        return $scoreCourseGrades;
    }

    /**
     * @param $studentId
     * @param $scId
     * @param $programId
     * @param $termId
     *
     * @return Collection
     */
    public function getScoresByUserIdAndSchoolIdAndProgramIdAndTermId($studentId, $scId, $programId,
                                                                      $termId): Collection
    {
        return DB::table($this->scoreView)
                 ->where('status', '=', StatusConstant::CONCLUDED)
                 ->where('school_id', '=', $scId)
                 ->when($termId, function ($query) use ($termId) {
                     return $query->where('term_id', '=', $termId);
                 })
                 ->when($studentId, function ($query) use ($studentId) {
                     return $query->where('user_id', '=', $studentId);
                 })
                 ->when($programId, function ($query) use ($programId) {
                     return $query->where('program_id', '=', $programId);
                 })
                 ->select('user_id', 'class_id', 'class_name as name', 'is_pass', 'subject_name as subject',
                          'real_weight', 'weight', 'credit as credits', 'grade_letter', 'score', 'term_name',
                          'extra_point_honor', 'extra_point_advanced', 'subject_type', 'gpa AS point',
                          'is_calculate_gpa')
                 ->orderBy('term_id', 'ASC')
                 ->get();
    }

    #[ArrayShape(['labels' => "mixed", 'data' => "array", 'data_percent' => "array"])]
    function getTopGpa(object $request): array
    {
        $rules = [
            'grade_id' => 'required|exists:grades,id'
        ];
        BaseService::doValidate($request, $rules);

        $termId    = ($request->term_id ?? null) ?: null;
        $orderType = $request->order_typ ?? 'top';
        $limit     = $request->limit ?? 5;
        $gradeId   = $request->grade_id;

        $topGpaPoints = $this->_getTopGpaPoints($termId, $gradeId, $orderType, $limit);

        $response         = [
            'labels'       => $topGpaPoints->toArray(),
            'data'         => [],
            'data_percent' => []
        ];
        $gpaService       = new GpaService();
        $countAllGpaPoint = $gpaService->countByTermIdAndGradeIdAndGpaPoint($termId, $gradeId);

        foreach ($topGpaPoints as $point) {
            $countGpaPoint                    = $gpaService
                ->countByTermIdAndGradeIdAndGpaPoint($termId, $gradeId, $point);
            $response['data'][$point]         = $countGpaPoint;
            $response['data_percent'][$point] = 100 * $countGpaPoint / $countAllGpaPoint;
        }

        $response['data']         = array_values($response['data']) ?? [];
        $response['data_percent'] = array_values($response['data_percent']) ?? [];

        return $response;
    }

    /**
     * @Author yaangvu
     * @Date   Aug 15, 2021
     *
     * @param int|null    $termId
     * @param int|null    $gradeId
     * @param string|null $orderType
     * @param int|null    $limit
     *
     * @return array|Collection
     */
    private function _getTopGpaPoints(?int    $termId = null, ?int $gradeId = null,
                                      ?string $orderType = 'top', ?int $limit = 5): array|Collection
    {
        $groupBy = $termId ? 'gpa' : 'cpa';

        return $this->gpa
            ->whereGradeId($gradeId)
            ->whereSchoolId(SchoolServiceProvider::$currentSchool->id)
            ->when($termId, function (EBuilder|Builder $query) use ($termId) {
                return $query->where('term_id', '=', $termId);
            })
            ->groupBy($groupBy)
            ->orderBy($groupBy, strtolower($orderType) === 'bottom' ? 'asc' : 'desc')
            ->limit($limit)
            ->pluck($groupBy);
    }

    #[ArrayShape(["attendance" => "array", "meta_data" => "array"])]
    public function getStatusDetailsDailyAttendance(Request $request): array
    {
        (new AttendanceService())->checkPermissionAttendanceReport();
        $rules = [
            'start_date'   => 'required',
            'end_date'     => 'required',
            'class_id'     => 'required|exists:classes,id,school_id,' . SchoolServiceProvider::$currentSchool->id,
            'status'       => 'required|array',
            'class_status' => Rule::in(StatusConstant::ALL),
            'user_id'      => 'exists:mongodb.users,_id'
        ];
        BaseService::doValidate($request, $rules);
        $dateStart     = Carbon::parse($request['start_date'])->format('Y-m-d');
        $dateEnd       = Carbon::parse($request['end_date'])->format('Y-m-d');
        $dateTimeStart = Carbon::createFromFormat('Y-m-d H:i:s', $request['start_date'], 'UTC')
                               ->setTimezone('UTC');
        $dateTimeEnd   = Carbon::createFromFormat('Y-m-d H:i:s', $request['end_date'], 'UTC')
                               ->setTimezone('UTC');
        $role          = RoleServiceProvider::$currentRole->name;
        try {
            $calendarIds = (new CalendarNoSQL())::query()
                                                ->where(function ($query) use ($dateTimeStart, $dateTimeEnd) {
                                                    $query->where('start', '>=', $dateTimeStart)
                                                          ->where('end', '<=', $dateTimeEnd);
                                                })
                                                ->orWhere(function ($query) use ($dateStart, $dateEnd) {
                                                    $query->where('start', '>=', $dateStart)
                                                          ->where('end', '<=', $dateEnd);
                                                })
                                                ->pluck('_id')->toArray();

            $attendances         = $this->_queryStatusDetail($request)
                                        ->when($role, function (EBuilder $q) use ($role, $request) {
                                            if ($role == RoleConstant::STUDENT)
                                                $q->where('attendances.user_id', BaseService::currentUser()->id);
                                            if ($role == RoleConstant::FAMILY) {
                                                $children = UserNoSQL::query()->where('_id', $request['children_id'])
                                                                     ->first();
                                                $child    = (new UserService())->getUserSqlViaUuid($children->uuid);
                                                $q->where('attendances.user_id', $child->id);
                                            }
                                        })
                                        ->whereIn('attendances.calendar_id', $calendarIds);
            $dataAttendances     = $attendances->get()->sortBy('userNoSql.full_name')->groupBy('end')->toArray();
            $dataDateAttendances = $attendances->pluck('attendances.end', 'attendances.calendar_id')->toArray();

            $dateAttendances = array_keys($dataDateAttendances);
            $numberStudents  = AttendanceSQL::query()->whereIn('calendar_id', $dateAttendances)
                                            ->select('end', DB::raw('count(*) as total'))
                                            ->groupBy('end')
                                            ->pluck('total', 'end')
                                            ->toArray();

            $metaDataCalendar = [];
            foreach ($dataDateAttendances as $keyAttendance => $dateAttendance) {
                $numberStudentsPresent = AttendanceSQL::query()->where('end', $dateAttendance)
                                                      ->whereIn('attendances.status',
                                                                AttendanceConstant::GROUP['attend'])
                                                      ->where('class_id', $request->class_id)
                                                      ->count();
                $calendar              = CalendarNoSQL::query()->where('_id', $keyAttendance)->first();
                if ($calendar->type == CalendarTypeConstant::VIDEO_CONFERENCE)
                    $metaDataCalendar[$dateAttendance]['comment'] = $calendar->zoom_meeting_comment;

                else
                    $metaDataCalendar[$dateAttendance]['comment'] = $calendar->description;
                $metaDataCalendar[$dateAttendance]['present_student'] = $numberStudentsPresent;
                $metaDataCalendar[$dateAttendance]['total_student']   = $numberStudents[$dateAttendance] ?? 0;

            }

            return [
                "attendance" => $dataAttendances,
                "meta_data"  => $metaDataCalendar
            ];

        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system - 500'), $e);
        }
    }

    public function _queryStatusDetail(Request $request)
    {
        $request     = request()->all();
        $currentDay  = Carbon::today();
        $status      = $request['status'];
        $classId     = $request['class_id'];
        $classStatus = $request['class_status'] ?? null;
        $userId      = $request['user_id'] ?? null;
        try {
            return (new UserSQL())->with('userNoSql')
                                  ->join('attendances', 'attendances.user_id', '=', 'users.id')
                                  ->join('classes', 'classes.id', '=', 'attendances.class_id')
                                  ->when($status, function ($query) use ($status) {
                                      if (!empty($status[0]))
                                          $query->whereIn('attendances.status', $status);
                                  })
                                  ->where('attendances.class_id', $classId)
                                  ->where('classes.school_id', SchoolServiceProvider::$currentSchool->id)
                                  ->when($classStatus, function ($q) use ($classStatus) {
                                      $q->where('classes.status', '=', $classStatus);
                                  })
                //->where('attendances.end', '<=', $currentDay)
                                  ->when($userId, function ($q) use ($userId) {
                    $user = UserNoSQL::query()->where('_id', $userId)->first();
                    $q->where('users.uuid', $user->uuid);
                })
                                  ->orderBy('attendances.end', 'DESC')
                                  ->select('users.*', 'attendances.*');
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system - 500'), $e);
        }
    }

    public function getStatusDetailAttendancesByClass(Request $request): LengthAwarePaginator
    {
        (new AttendanceService())->checkPermissionAttendanceReport();
        $rules = [
            'user_id'  => 'required|exists:mongodb.users,_id',
            'class_id' => 'required|exists:classes,id,school_id,' . SchoolServiceProvider::$currentSchool->id,
            'status'   => 'required|array'
        ];
        BaseService::doValidate($request, $rules);
        try {
            $user = (new UserNoSQL())->where('_id', $request->user_id)->first();

            return $this->_queryStatusDetail($request)->where('users.uuid', $user->uuid)
                        ->paginate(QueryHelper::limit());
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system - 500'), $e);
        }
    }

    private function _filerGpa(null|string|int $student_id, ?int $program_id, ?int $termId)
    {
        return $this->gpa
            ->when($student_id, function ($query) use ($student_id) {
                return $query->where('user_id', '=', $student_id);
            })
            ->when($program_id, function ($query) use ($program_id) {
                return $query->where('program_id', $program_id);
            })
            ->when($termId, function ($query) use ($termId) {
                return $query->where('term_id', $termId);
            });
    }

    #[ArrayShape(['labels' => "string[]", 'data' => "string[]", 'details' => "object[]"])]
    public function getStatusChartTaskManagement(object $request): array
    {
        $isTeacher = $this->_validateAndGetIsTeacher($request);

        $queryCalculatePercentageStatus = $this->_queryFilterChartTaskManagement($request, $isTeacher)
                                               ->selectRaw('task_status.name as status_name , ROUND(count(*) * 100.0 / sum(count(*)) over(),2) as percent , count(*) as total')
                                               ->join(TaskStatus::table, 'sub_tasks.task_status_id', '=',
                                                      'task_status.id')
                                               ->groupBy('task_status.name');

        $percentageStatus = TaskStatusSQL::query()
                                         ->selectRaw('task_status."name" as status, coalesce(sub_table.percent, 0) as percentage , coalesce(sub_table.total, 0) as number_of_tasks')
                                         ->leftJoinSub($queryCalculatePercentageStatus, 'sub_table',
                                                       'sub_table.status_name', '=', 'task_status.name')
                                         ->get();

        return [
            'labels'  => $percentageStatus->pluck('status')->toArray(),
            'data'    => array_map('floatval', $percentageStatus->pluck('percentage')->toArray()),
            'details' => $percentageStatus
        ];
    }

    #[ArrayShape(['labels' => "string[]", 'data' => "string[]", 'details' => "object[]"])]
    public function getTimelinessChartTaskManagement(object $request): array
    {
        $isTeacher = $this->_validateAndGetIsTeacher($request);

        $today                = Carbon::now()->toDateString();
        $threeDaysBeforeToday = Carbon::now()->addDays(3)->toDateString();
        $taskManagements      = $this->_queryFilterChartTaskManagement($request, $isTeacher)
                                     ->join('task_status', 'task_status.id', '=', 'sub_tasks.task_status_id')
                                     ->where('task_status.name', TaskManagementConstant::IN_PROGRESS)
                                     ->get();

        $total         = 0;
        $numberOfTasks = [];
        foreach (TaskManagementConstant::ALL_TIMELESS as $timeless) {
            $countTimeless            = match ($timeless) {
                TaskManagementConstant::WARNING =>
                $taskManagements->whereBetween('deadline', [$today, $threeDaysBeforeToday])->count(),
                TaskManagementConstant::ACCEPTED_TIME =>
                $taskManagements->where('deadline', '>', $threeDaysBeforeToday)->count(),
                TaskManagementConstant::OVERDUE =>
                $taskManagements->where('deadline', '<', $today)->count(),
                default => 0
            };
            $numberOfTasks[$timeless] = (object)[
                'timeliness'      => $timeless,
                'number_of_tasks' => $countTimeless
            ];
            $total                    += $countTimeless;
        }
        foreach ($numberOfTasks as $numberOfTask)
            $numberOfTask->percentage = $total == 0 ? 0 : round(($numberOfTask->number_of_tasks / $total) * 100, 2);

        $percentageWarning = $numberOfTasks[TaskManagementConstant::WARNING];
        $percentageOnTime  = $numberOfTasks[TaskManagementConstant::ACCEPTED_TIME];
        $percentageOverdue = $numberOfTasks[TaskManagementConstant::OVERDUE];

        return [
            'labels'  => [
                TaskManagementConstant::WARNING,
                TaskManagementConstant::ACCEPTED_TIME,
                TaskManagementConstant::OVERDUE,
            ],
            'data'    => [
                $percentageWarning->percentage,
                $percentageOnTime->percentage,
                $percentageOverdue->percentage,
            ],
            'details' => [
                $percentageWarning,
                $percentageOnTime,
                $percentageOverdue
            ]
        ];
    }

    private function _queryFilterChartTaskManagement(object $request, bool $isTeacher): EBuilder
    {
        $query = SubTaskSQL::query()->whereBetween('sub_tasks.deadline', [$request->start_date, $request->end_date]);

        $userId = $isTeacher ? BaseService::currentUser()->id : UserService::getViaId($request->user_id)->userSql->id;

        switch ($request->action) {
            case TaskManagementConstant::REPORTED :
                $query->where('sub_tasks.assignee_id', $userId);
                break;
            case TaskManagementConstant::REVIEWED :
                $query->where('sub_tasks.reviewer_id', $userId);
                break;
            case TaskManagementConstant::CREATED :
                $query->where('sub_tasks.owner_id', $userId);
                break;
            default :
        }

        return $query;
    }

    /**
     * @Description
     *
     * @Author kyhoang
     * @Date   Jun 28, 2022
     *
     * @param object $request
     *
     * @return bool
     */
    private function _validateAndGetIsTeacher(object $request): bool
    {
        $rules = [
            'start_date' => 'required|date_format:Y-m-d',
            'end_date'   => 'required|date_format:Y-m-d',
            'user_id'    => 'required|exists:mongodb.users,_id,deleted_at,NULL',
            'action'     => 'required|in:' . implode(',', TaskManagementConstant::ALL_ACTION)
        ];
        BaseService::doValidate($request, $rules);
        $roleTeacherId = RoleService::getViaName(RoleConstant::TEACHER)->id;
        $isTeacher     = $this->hasAnyRole(RoleConstant::TEACHER)
            && RoleServiceProvider::$currentRole->id == $roleTeacherId;
        $isGod         = $this->isGod();
        $isCounselor   = $this->hasAnyRole(RoleConstant::COUNSELOR);
        $isDynamic     = $this->hasPermissionViaRoleId(
            PermissionConstant::taskManagement(PermissionActionConstant::REPORT),
            RoleServiceProvider::$currentRole->id
        );

        if (!$isTeacher && !$isGod && !$isDynamic && !$isCounselor)
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());

        return $isTeacher;
    }

    public function getCommunicationLog(object $request): LengthAwarePaginator
    {
        $rules = [
            'methods'          => 'array',
            'concerns'         => 'array',
            'methods.*'        => 'in:' . implode(',', CommunicationLogConstant::METHODS),
            'concerns.*'       => 'in:' . implode(',', CommunicationLogConstant::CONCERNS),
            'date_contact.*'   => 'date_format:Y-m-d',
            'date_contact'     => 'array|min:2|max:2',
            'staff_contact_id' => 'exists:mongodb.users,_id',
            'student_id'       => 'exists:mongodb.users,_id'
        ];
        BaseService::doValidate($request, $rules);
        $isTeacherOrCounselor = $this->hasAnyRole(RoleConstant::TEACHER, RoleConstant::COUNSELOR);
        $isGodOrDynamic
                              = $this->hasPermission(PermissionConstant::communicationLogReport(PermissionActionConstant::VIEW));
        if (!$isGodOrDynamic && !$isTeacherOrCounselor) {
            throw new BadRequestException(__('forbidden.forbidden'), new Exception());
        }
        $studentId      = $request->student_id;
        $staffContactId = $request->staff_contact_id;
        $methods        = $request->methods;
        $concerns       = $request->concerns;

        $dateContact          = $request->date_contact ? self::handleFilterDate($request->date_contact) : null;
        $assignedStudentUuids = [];
        if ($isTeacherOrCounselor && !$isGodOrDynamic) {
            $currentUser          = BaseService::currentUser()->userNoSql ?? null;
            $assignedStudentUuids = $currentUser->assigned_student_uuids ?? [];
        }
        $students = UserNoSQL::query()->whereIn('uuid', $assignedStudentUuids)->pluck('_id')->toArray();

        return CommunicationLogNoSql::query()->with(['createdBy.userNoSql', 'student'])
                                    ->when(!empty($students), function ($q) use ($students) {
                                        $q->whereIn('student_id', $students);
                                    })
                                    ->when($studentId, function ($q) use ($studentId) {
                                        $q->where('student_id', $studentId);
                                    })
                                    ->when($staffContactId, function ($q) use ($staffContactId) {
                                        $staffNoSql = UserNoSQL::query()->where('_id', $staffContactId)->first();
                                        $staffSql   = UserSQL::query()->where('uuid', $staffNoSql->uuid)->first();
                                        $q->where('created_by', $staffSql->id);
                                    })
                                    ->when($dateContact, function ($q) use ($dateContact) {
                                        $dateStart = $dateContact['start_date'];
                                        $dateEnd   = $dateContact['end_date'];
                                        $q->whereDate('date_of_contact', '>=', $dateStart);
                                        $q->whereDate('date_of_contact', '<=', $dateEnd);
                                    })
                                    ->when($methods, function ($q) use ($methods) {
                                        $q->whereIn('method', $methods);
                                    })
                                    ->when($concerns, function ($q) use ($concerns) {
                                        $q->whereIn('concerns', $concerns);
                                    })
                                    ->where('school_uuid', SchoolServiceProvider::$currentSchool->uuid)
                                    ->orderBy('date_of_contact', 'desc')
                                    ->paginate(QueryHelper::limit());

    }


    #[ArrayShape(['start_date' => "mixed", 'end_date' => "mixed"])]
    public static function handleFilterDate(array $dates): array
    {
        return [
            'start_date' => min($dates[0], $dates[1]),
            'end_date'   => max($dates[0], $dates[1])
        ];
    }

}
