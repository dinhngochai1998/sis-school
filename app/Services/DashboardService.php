<?php
/**
 * @Author Edogawa Conan
 * @Date   Aug 29, 2021
 */

namespace App\Services;

use DB;
use Exception;
use Illuminate\Database\Eloquent\Builder as EBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Log;
use JetBrains\PhpStorm\ArrayShape;
use MongoDB\Driver\Cursor;
use YaangVu\Constant\ClassAssignmentConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Constant\StatusConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\ForbiddenException;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\ClassAssignmentSQL;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\impl\GradeSQL;
use YaangVu\SisModel\App\Models\impl\GraduationCategorySQL;
use YaangVu\SisModel\App\Models\impl\RoleSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;


class DashboardService
{
    use RoleAndPermissionTrait;

    public Cursor $cursors;

    protected string $scoreView = 'score_view';

    protected const MAX_CPA_BONUS_POINTS = 4.80;

    /**
     * @Author Edogawa Conan
     * @Date   Aug 29, 2021
     *
     * @return array
     */
    #[ArrayShape(['total_teacher' => "int", 'total_student' => "int", 'total_counselor' => "int", 'total_class' => "int"])]
    public function getSyntheticForDashBoard(): array
    {
        return [
            'total_teacher'   => $this->_countUserViaRoleName(RoleConstant::TEACHER),
            'total_student'   => $this->_countUserViaRoleName(RoleConstant::STUDENT),
            'total_counselor' => $this->_countUserViaRoleName(RoleConstant::COUNSELOR),
            'total_class'     => ClassSQL::whereStatus(StatusConstant::ON_GOING)->count()
        ];
    }

    /**
     * @Author Edogawa Conan
     * @Date   Aug 29, 2021
     *
     * @return array
     */
    #[ArrayShape(['label' => "string[]", 'data' => "array"])]
    function getPercentageGender(): array
    {
        $this->cursors = $this->groupViaColumn('sex');
        $other         = null;
        $male = $female = 0;
        foreach ($this->cursors as $cursor) {
            $serialize = $cursor->bsonSerialize();

            switch ($serialize->_id) {
                case 'Male' :
                    $male = $serialize->count;
                    break;
                case 'Female' :
                    $female = $serialize->count;
                    break;
                default :
                    $other += $serialize->count;
                    break;
            }
        }

        return [
            'label' => [
                'Male',
                'Female',
                'Other'
            ],
            'data'  => [
                $male,
                $female,
                $other
            ]
        ];
    }

    /**
     * @Author Edogawa Conan
     * @Date   Aug 29, 2021
     *
     * @return array
     */
    #[ArrayShape(['label' => "mixed", 'data' => "array"])]
    function getPercentageGrade(): array
    {
        $this->cursors = $this->groupViaColumn('grade');
        $gradeNames    = GradeSQL::orderBy('id', 'ASC')->pluck('name');
        $total         = 0;
        $flat          = [];
        $other         = 0;
        foreach ($this->cursors as $cursor) {
            $serialize = $cursor->bsonSerialize();
            if ($serialize->_id == "")
                $other += $serialize->count;
            else
                $flat[$serialize->_id] = $serialize->count;
            $total += $serialize->count;
        }
        foreach ($gradeNames as $gradeName) {
            $gradeCount = $flat[$gradeName] ?? 0;
            $data[]     = $gradeCount;
        }
        $gradeNames[] = 'Undefined';
        $data[]       = $other;

        return [
            'label' => $gradeNames,
            'data'  => $data
        ];
    }

    private function groupViaColumn(string $column): mixed
    {
        $roleStudentId               = RoleSQL::whereName(RoleService::decorateWithScId(RoleConstant::STUDENT))->first()?->id;
        $aggregate             = [];
        $aggregate[]['$match'] = [
            'status'     => [
                '$eq' => StatusConstant::ACTIVE
            ],
            'role_ids' => [
                '$all' => [$roleStudentId]
            ],
        ];
        $aggregate[]['$group'] = [
            '_id'   => "$$column",
            'count' => [
                '$sum' => 1
            ]
        ];

        return DB::connection('mongodb')->collection('users')->raw(function ($collection) use ($aggregate) {
            return $collection->aggregate($aggregate);
        });
    }

    /**
     * @Author Edogawa Conan
     * @Date   Sep 01, 2021
     *
     * @param string $roleName
     *
     * @return int
     */
    private function _countUserViaRoleName(string $roleName): int
    {
        $roleName = RoleService::decorateWithScId($roleName);
        $roleStudentId = RoleSQL::whereName($roleName)->first()?->id;
        return UserNoSQL::with([])
                        ->where("role_ids", 'all', [$roleStudentId])
                        ->where('status', '=', StatusConstant::ACTIVE)
                        ->count();
    }


    public function getStudentsOverview(): array
    {
        $userUuid = BaseService::currentUser()?->uuid ?? null;
        $userId   = BaseService::currentUser()?->id ?? null;
        $user     = (new UserService())->getByUuid($userUuid);

        if (!$this->hasAnyRole(RoleConstant::TEACHER) && !$this->isMe($user))
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());

        try {
            $classOfTeacher = ClassSQL::join('class_assignments', 'class_assignments.class_id', '=', 'classes.id')
                                      ->where('class_assignments.user_id', $userId)
                                      ->where('classes.status', StatusConstant::ON_GOING)
                                      ->whereIn('assignment', [
                                          ClassAssignmentConstant::SECONDARY_TEACHER,
                                          ClassAssignmentConstant::PRIMARY_TEACHER
                                      ])
                                      ->pluck('class_assignments.class_id');
            if (!$classOfTeacher) {
                Log::info(__("user-not-exist-class", ['attribute' => __('entity')]) . ": $userId");

                return [];
            }
            $uuids = ClassAssignmentSQL::join('users', 'users.id', '=', 'class_assignments.user_id')
                                       ->where('assignment', ClassAssignmentConstant::STUDENT)
                                       ->whereIn('class_id', $classOfTeacher)
                                       ->pluck('users.uuid');

            $listUuid = $uuids->filter(function ($uuid, $key) {
                return !empty($uuid);
            });

            $data          = UserNoSQL::whereIn('uuid', $listUuid)->get();
            $totalStudents = $data->count();

            return [
                'total' => $totalStudents,
                'list'  => $data->toArray()
            ];
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    #[ArrayShape(['labels' => "array", 'data' => "array"])]
    public function getClassesOverview(): array
    {
        $userUuid = BaseService::currentUser()?->uuid ?? null;
        $userId   = BaseService::currentUser()?->id ?? null;
        $user     = (new UserService())->getByUuid($userUuid);

        if (!$this->hasAnyRole(RoleConstant::TEACHER) && !$this->isMe($user))
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());

        try {
            $classOfTeacher = ClassSQL::join('class_assignments', 'class_assignments.class_id', '=', 'classes.id')
                                      ->where('class_assignments.user_id', $userId)
                                      ->whereIn('classes.status', [StatusConstant::ON_GOING, StatusConstant::CONCLUDED])
                                      ->whereIn('class_assignments.assignment', [
                                          ClassAssignmentConstant::SECONDARY_TEACHER,
                                          ClassAssignmentConstant::PRIMARY_TEACHER
                                      ])->get();
            $countsClasses  = $classOfTeacher->countBy('status');
            $response       = [
                'labels' => [],
                'data'   => []
            ];
            foreach ($countsClasses as $status => $count) {
                $response['labels'][] = $status;
                $response['data'][]   = $count;
            }

            return $response;
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @param object $request
     *
     * @return array
     */
    public function getCpaSummary(object $request): array
    {
        $userUuid = BaseService::currentUser()?->uuid ?? null;
        $userId   = BaseService::currentUser()?->id ?? null;
        $user     = (new UserService())->getByUuid($userUuid);
        $schoolId = SchoolServiceProvider::$currentSchool?->id;

        BaseService::doValidate($request, [
            'program_id' => 'required|exists:programs,id',
        ]);

        if (!$this->isStudent() && !$this->isMe($user))
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());

        try {
            $programId      = $request->program_id ?? null;
            $currentCpa     = (new CpaService())->getCurrentCpa($userId, $programId, $schoolId)
                                                ->join('programs', 'programs.id', '=', 'cpa.program_id')
                                                ->select('cpa.cpa', 'cpa.bonus_cpa', 'programs.name')
                                                ->first();
            $cpa            = $currentCpa?->cpa ?? null;
            $bonusPoint     = $currentCpa?->bonus_point ?? null;
            $cpaWBonusPoint = $cpa += $bonusPoint;

            if ($cpaWBonusPoint >= self::MAX_CPA_BONUS_POINTS) {
                $cpaWBonusPoint = self::MAX_CPA_BONUS_POINTS;
            }

            $program          = (new ProgramService())->get($programId);
            $classesOfStudent = GraduationCategorySQL::select('ca.user_id', 'ca.class_id')
                                                     ->join('program_graduation_category as pgc',
                                                            'pgc.graduation_category_id', '=',
                                                            'graduation_categories.id')
                                                     ->join('programs as p', function ($join) use ($programId) {
                                                         $join->on('p.id', '=', 'pgc.program_id');
                                                         $join->when($programId,
                                                             function (EBuilder|Builder $query) use ($programId) {
                                                                 return $query->where('p.id', $programId);
                                                             });
                                                     })->join('graduation_category_subject as gcs',
                                                              'gcs.graduation_category_id', '=',
                                                              'graduation_categories.id')
                                                     ->join('subjects as s', 's.id', '=', 'gcs.subject_id')
                                                     ->join('classes as c', 'c.subject_id', '=', 's.id')
                                                     ->join('class_assignments as ca',
                                                         function ($join) use ($userId) {
                                                             $join->on('ca.class_id', '=', 'c.id');
                                                             $join->where('ca.assignment', '=',
                                                                          ClassAssignmentConstant::STUDENT);
                                                             $join->when($userId,
                                                                 function (EBuilder|Builder $query) use ($userId) {
                                                                     return $query->where('ca.user_id', $userId);
                                                                 });
                                                         })
                                                     ->where('c.status', StatusConstant::ON_GOING)
                                                     ->whereNull(['pgc.deleted_at', 'p.deleted_at',
                                                                  'gcs.deleted_at', 's.deleted_at',
                                                                  'c.deleted_at', 'ca.deleted_at'])
                                                     ->get();
            $countClasses     = $classesOfStudent->count();

            return [
                "program_name"           => $program?->name ?? null,
                "total_enrolled_classes" => $countClasses,
                "cpa"                    => $cpa,
                "cpa_wbonus_point"       => $cpaWBonusPoint
            ];

        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

}
