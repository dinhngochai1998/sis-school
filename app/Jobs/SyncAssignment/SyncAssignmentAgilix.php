<?php
/**
 * @Author yaangvu
 * @Date   Sep 24, 2021
 */

namespace App\Jobs\SyncAssignment;

use App\Models\AgilixCourse;
use App\Models\AgilixEnrollment;
use App\Models\AgilixRole;
use App\Services\ClassAssignmentService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\UTCDateTime;
use YaangVu\Constant\ClassAssignmentConstant;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\Constant\StatusConstant;

class SyncAssignmentAgilix extends SyncAssignment
{
    protected string $lmsName                = LmsSystemConstant::AGILIX;
    protected string $table                  = 'lms_agilix_enrollments';
    protected array  $classHasPrimaryTeacher = [];

    public function handle()
    {
        parent::handle();

        $roles       = $this->_getRoles();
        $assignments = [];

        Log::info($this->instance . " ----- Started sync LMS $this->lmsName assignments for school: $this->school");

        $agilixAssignments = $this->getData();

        foreach ($agilixAssignments as $agilixAssignment) {
            $classId = $agilixAssignment->classSql->id ?? null;
            $userId  = $agilixAssignment->userNoSql->userSql->id ?? null;
            $status  = $agilixAssignment->status ?? null;
            // If user or class is not mapping
            if ($classId === null || $userId === null)
                continue;

            // If you have no new data to continue
            if ($agilixAssignment->pulledat?->toDateTime() < $agilixAssignment->{$this->jobName . '_at'}?->toDateTime())
                continue;

            $assignment = $this->_getClassRoleMapping($roles->get($agilixAssignment->roleid)?->name, $classId, $userId);
            // If assignment is not valid
            if ($assignment == null) {
                $this->callback($agilixAssignment, false);
                continue;
            }

            $assignmentDto = new AssignmentDto();
            $assignmentDto->setClassId($classId);
            $assignmentDto->setUserId($userId);
            $assignmentDto->setAssignment($assignment);
            $assignmentDto->setExternalId($agilixAssignment->id);
            $assignmentDto->setStatus($this->_getStatusMapping($status));
            // $assignments[$classId][$assignment][] = $userId;
            $assignments[] = $assignmentDto;
            $this->callback($agilixAssignment);
        }
        // dd($assignments);
        $assignmentService = new ClassAssignmentService();
        $assignmentService->syncAssignmentsFromLms($assignments);
        // $this->sync($assignments);

        Log::info($this->instance . " ----- Ended sync LMS $this->lmsName assignments for school: $this->school");
    }

    /**
     * @Description
     *
     * @Author yaangvu
     * @Date   Sep 29, 2021
     *
     * @return array|Collection
     */
    public function getData(): array|Collection
    {
        $courseIds = $this->getCourseIds();

        AgilixCourse::whereIn('id', $courseIds)->update(
            [
                $this->jobName . '_at'     => new UTCDateTime(Carbon::now()->toDateTime()),
                $this->jobName . '_status' => true
            ]
        );

        return AgilixEnrollment::with(['classSql', 'userNoSql.userSql'])
                               ->whereIn('courseid', $courseIds)
                               ->get();
    }

    /**
     * @Description
     *
     * @Author yaangvu
     * @Date   Sep 29, 2021
     *
     * @return Collection|array
     */
    private function getCourseIds(): Collection|array
    {
        return AgilixCourse::orderBy($this->jobName . '_at')
            // ->where('id', '=', '174374319')
                           ->limit($this->limit)
                           ->get()
                           ->pluck('id');
    }

    /**
     * @Description
     *
     * @Author yaangvu
     * @Date   Sep 29, 2021
     *
     * @param string|null     $role
     * @param int|string|null $classId
     * @param int|string|null $userId
     *
     * @return string|null
     */
    private function _getClassRoleMapping(?string $role, int|string|null $classId, int|string|null $userId): ?string
    {
        return match ($role) {
            'Student' => ClassAssignmentConstant::STUDENT,
            'Teacher' => $this->_checkClassHasPrimaryTeacher($classId),
            default => null,
        };
    }

    private function _getStatusMapping(?int $status): ?string
    {
        // 4 withdraw , 7 completed , 10 inactive , 1 active
        return match ($status) {
            1 => StatusConstant::ACTIVE,
            4 => StatusConstant::WITHDRAWAL,
            7 => StatusConstant::COMPLETED,
            10 => StatusConstant::INACTIVE,
            default => null
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

    /**
     * @Description
     *
     * @Author yaangvu
     * @Date   Sep 24, 2021
     *
     * @return array|Collection
     */
    private function _getRoles(): array|Collection
    {
        return AgilixRole::where('domainid', '=', '1') // <--> domainname = Root
                         ->get()
                         ->keyBy('id');
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

}
