<?php


namespace App\Jobs\SyncAssignment;


use App\Models\EdmentumClass;
use App\Services\ClassAssignmentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use YaangVu\Constant\ClassAssignmentConstant;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;

class SyncAssignmentEdmentum extends SyncAssignment
{
    protected string $lmsName                = LmsSystemConstant::EDMENTUM;
    protected string $table                  = 'lms_edmentum_classes';
    protected array  $classHasPrimaryTeacher = [];

    public function handle()
    {
        Log::info($this->instance . " ----- Started sync LMS $this->lmsName assignments for school");
        $edmentumClasses    = $this->getData();
        $assignments        = [];
        $assignmentsStudent = [];
        $assignmentsTeacher = [];

        foreach ($edmentumClasses as $edmentumClass) {
            // If you have no new data to continue
            if ($edmentumClass->pulledat?->toDateTime() < $edmentumClass->{$this->jobName . '_at'}?->toDateTime())
                continue;

            if ($edmentumClass->classSql == null) {
                continue;
            }
            if (is_array($edmentumClass->Learners)) {
                $assignmentsStudent = $this->_handleData($edmentumClass->Learners, $edmentumClass->classSql['id'],
                                                         RoleConstant::STUDENT);

                $assignmentsStudent[ClassAssignmentConstant::STUDENT] ??= [];
            }
            if (is_array($edmentumClass->Teachers)) {
                $assignmentsTeacher = $this->_handleData($edmentumClass->Teachers, $edmentumClass->classSql['id'],
                                                         RoleConstant::TEACHER);

                $assignmentsTeacher[ClassAssignmentConstant::PRIMARY_TEACHER]   ??= [];
                $assignmentsTeacher[ClassAssignmentConstant::SECONDARY_TEACHER] ??= [];
            }

            $assignments[$edmentumClass->classSql['id']] = array_merge($assignmentsStudent, $assignmentsTeacher);

            $this->callback($edmentumClass);
        }

        $this->sync($assignments);

        Log::info($this->instance . " ----- Ended sync LMS $this->lmsName assignments for school");
    }

    public function getData(): array|Collection
    {
        return EdmentumClass::with('classSql')
            // ->where('ClassId', '=', 2748)
                            ->orderBy($this->jobName . '_at')
                            ->limit($this->limit)
                            ->get();
    }

    public function sync($data): void
    {
        $service = new ClassAssignmentService();
        foreach ($data as $classId => $roles) {
            foreach ($roles as $role => $userIds) {
                $service->sync($classId, $role, $userIds);
            }
        }
    }

    private function _handleData(array $edmentumIds, int|string|null $classId, ?string $role): array
    {
        $assignments = [];
        $edmentumIds = array_map(function ($int) {
            return (string)$int;
        }, $edmentumIds);

        $users = UserNoSQL::with('userSql')
                          ->whereIn('edmentum_id', $edmentumIds)
                          ->get();
        foreach ($users as $user) {
            if ($user?->userSql == null) {
                continue;
            }
            $assignment                 = $this->_getClassRoleMapping($role, $classId, $user?->userSql['id']);
            $assignments[$assignment][] = $user?->userSql['id'];
        }

        return $assignments;
    }

    private function _getClassRoleMapping(?string $role, int|string|null $classId, int|string|null $userId): ?string
    {
        return match ($role) {
            'Student' => ClassAssignmentConstant::STUDENT,
            'Teacher' => $this->_checkClassHasPrimaryTeacher($classId),
            default => null,
        };
    }

    private function _checkClassHasPrimaryTeacher(int|string|null $classId): string
    {
        if (in_array($classId, $this->classHasPrimaryTeacher)) {
            return ClassAssignmentConstant::SECONDARY_TEACHER;
        } else {
            $this->classHasPrimaryTeacher[] = $classId;

            return ClassAssignmentConstant::PRIMARY_TEACHER;
        }
    }

}
