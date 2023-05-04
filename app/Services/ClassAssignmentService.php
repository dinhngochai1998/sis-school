<?php


namespace App\Services;


use App\Jobs\SyncAssignment\AssignmentDto;
use App\Traits\AgilixTraits;
use App\Traits\EdmentumTraits;
use Carbon\Carbon;
use Exception;
use Faker\Provider\Uuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as MBuilder;
use Illuminate\Support\Collection;
use Log;
use Throwable;
use YaangVu\Constant\ClassAssignmentConstant;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\Constant\StatusConstant;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\ClassAssignmentSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Models\impl\UserSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;

class ClassAssignmentService extends BaseService
{
    public Model|Builder|ClassAssignmentSQL $model;

    use EdmentumTraits, AgilixTraits;

    /**
     * @Description get class assignment via user_id and class_id
     *
     * @Author      hoang
     * @Date        Apr 02, 2022
     *
     * @param int|string $userId
     * @param int|string $classId
     *
     * @return Model|ClassAssignmentSQL|Builder|null
     */
    public function getViaUserIdAndClassId(int|string $userId,
                                           int|string $classId): Model|ClassAssignmentSQL|Builder|null
    {
        return $this->model->where('user_id', $userId)
                           ->where('class_id', $classId)
                           ->first();
    }

    function createModel(): void
    {
        $this->model = new ClassAssignmentSQL();
    }

    public function getViaClassIdAndAssignment(int $classId, array $assignment, int $userId = null): Collection|array
    {
        return $this->model->where('class_id', $classId)
                           ->whereIn('assignment', $assignment)
                           ->when($userId, function ($q) use ($userId) {
                               $q->where('user_id', $userId);
                           })
                           ->get();
    }

    /**
     * Sync Assignment via ClassId & RoleId & array UserIds
     *
     * @param int|string       $classId
     * @param string           $assignment
     * @param array|Collection $userIds
     */
    function sync(int|string $classId, string $assignment, array|Collection $userIds)
    {
        Log::info("Sync Class assignments with classId: $classId, assignment: $assignment, userIds: ",
                  ($userIds instanceof Collection) ? $userIds->toArray() : $userIds);

        // Delete all Assignment old
        ClassAssignmentSQL::whereClassId($classId)
                          ->whereAssignment($assignment)
                          ->forceDelete();

        $assignments             = [];
        $position                = 0;
        $classActivityLmsService = new ClassActivityLmsService();
        foreach ($userIds as $userId) {
            $assignments[] = [
                'uuid'       => Uuid::uuid(),
                'class_id'   => $classId,
                'assignment' => $assignment,
                'position'   => $assignment === ClassAssignmentConstant::SECONDARY_TEACHER ? $position : null,
                'user_id'    => $userId,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
            $position++;

            if (!$classActivityLmsService->getViaUserIdAndClassId($userId, $classId))
                $classActivityLmsService->addFakeDataViaUserIdAndCLassId($userId, $classId);
        }

        if ($assignments)
            ClassAssignmentSQL::query()->insert($assignments);
    }

    /**
     * @Description Sync assignment from LMS
     *
     * @Author      yaangvu
     * @Date        Mar 16, 2022
     *
     * @param AssignmentDto[] $assignments
     */
    public function syncAssignmentsFromLms(array $assignments)
    {
        $classIds         = [];
        $classAssignments = [];
        $positions        = []; // ['class_id' => int, 'position' => int]
        foreach ($assignments as $assignment) {
            $classIds[] = $assignment->getClassId();
            $classId    = $assignment->getClassId();
            if ($assignment->getAssignment() == ClassAssignmentConstant::SECONDARY_TEACHER) {
                $positions[$classId] = isset($positions[$classId])
                    ? ++$positions[$classId]
                    : 0;
            }

            $classAssignments[] = [
                'uuid'        => Uuid::uuid(),
                'class_id'    => $assignment->getClassId(),
                'assignment'  => $assignment->getAssignment(),
                'position'    => $assignment->getAssignment() == ClassAssignmentConstant::SECONDARY_TEACHER
                    ? $positions[$classId]
                    : null,
                'user_id'     => $assignment->getUserId(),
                'external_id' => $assignment->getExternalId(),
                'status'      => $assignment->getStatus()
            ];
        }
        // Delete all Assignment old
        ClassAssignmentSQL::query()->whereIn('class_id', $classIds)->forceDelete();

        if ($classAssignments)
            ClassAssignmentSQL::query()->insert($classAssignments);
    }

    /**
     *
     * @param int|string       $classId
     * @param string           $assignment
     * @param array|Collection $users
     */
    function assigmentTeachers(int|string $classId, string $assignment, array|Collection $users)
    {
        // Delete all Assignment old
        ClassAssignmentSQL::whereClassId($classId)
                          ->whereAssignment($assignment)
                          ->forceDelete();

        $teachers = [];
        foreach ($users as $user) {
            $teachers[] = [
                'uuid'       => Uuid::uuid(),
                'class_id'   => $classId,
                'assignment' => $assignment,
                'user_id'    => $user->user_id,
                'position'   => $user->position,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
        }

        if ($teachers)
            ClassAssignmentSQL::query()->insert($teachers);
    }

    /**
     * @Description Get Main teacher of class
     *
     * @Author      yaangvu
     * @Date        Sep 11, 2021
     *
     * @param int|string $classId
     *
     * @return Model|Builder|UserSQL|null
     */
    function getMainTeacher(int|string $classId): Model|Builder|UserSQL|null
    {
        return $this->model
            ->with('users.userNoSql')
            ->join('users', 'users.id', '=', 'class_assignments.user_id')
            ->where('assignment', '=', ClassAssignmentConstant::TEACHER)
            ->where('class_id', '=', $classId)
            ->first();
    }

    /**
     * @Description
     *
     * @Author yaangvu
     * @Date   Sep 23, 2021
     *
     * @param int|null    $termId
     * @param array|null  $classIds
     * @param string|null $assignment
     *
     * @return int
     */
    public function countStudentByTermIdAndClassIds(?int $termId, ?array $classIds, ?string $assignment): int
    {
        $table = $this->model->getTable();

        return $this->model
            ->join('classes', 'classes.id', '=', "$table.class_id")
            ->where("classes.school_id", '=', SchoolServiceProvider::$currentSchool->id)
            ->when($termId, function (MBuilder|Builder $q) use ($termId) {
                return $q->where("classes.term_id", '=', $termId);
            })
            ->when($classIds, function (MBuilder|Builder $q) use ($classIds, $table) {
                return $q->whereIn("$table.class_id", $classIds);
            })
            ->when($assignment, function (MBuilder|Builder $q) use ($assignment, $table) {
                return $q->where("$table.assignment", '=', $assignment);
            })
            ->count();
    }

    /**
     * @Description Check class have or not Primary Teacher, who is different with $userId
     *
     * @Author      yaangvu
     * @Date        Sep 24, 2021
     *
     * @param int|string $classId
     * @param int|string $userId
     *
     * @return bool
     */
    public function hasOtherPrimaryTeacherVsUserId(int|string $classId, int|string $userId): bool
    {
        return $this->model->whereClassId($classId)
                           ->whereUserId($userId)
                           ->whereAssignment(ClassAssignmentConstant::PRIMARY_TEACHER)
                           ->count() > 1;
    }

    /**
     * @Description update status assign user from class
     *
     * @Author      hoang
     * @Date        Apr 02, 2022
     *
     * @param string|int $id
     * @param object     $request
     *
     * @return bool
     * @throws Exception
     * @throws Throwable
     */
    public function updateStatusAssign(string|int $id, object $request): bool
    {
        $classService = new ClassService();
        $scId         = SchoolServiceProvider::$currentSchool->uuid;
        $class        = $classService->get($id);
        $status       = [
            StatusConstant::ACTIVE,
            StatusConstant::COMPLETED,
            StatusConstant::INACTIVE,
            StatusConstant::WITHDRAWAL
        ];
        $this->doValidate($request, [
            'assignments.*.user_id' => "required|exists:mongodb.users,_id,sc_id,$scId",
            'assignments.*.status'  => 'required|in:' . implode(',', $status)
        ]);
        $userWithdrawalIds = [];
        $userActiveIds     = [];
        foreach ($request->assignments as $assignment) {
            $userSQL = (new UserService())->get($assignment['user_id'])->userSql;
            if ($assignment['status'] == StatusConstant::WITHDRAWAL)
                $userWithdrawalIds[] = $userSQL->id;
            if ($assignment['status'] == StatusConstant::ACTIVE)
                $userActiveIds[] = $userSQL->id;
            $classAssignment = $this->getViaUserIdAndClassId($userSQL->id, $class->id);
            if (!$classAssignment)
                continue;
            $assignmentStatus        = $assignment['status'];
            $classAssignment->status = $assignmentStatus;
            $classAssignment->save();

            $this->changeStatusEnrollToLms($classAssignment->id, $assignmentStatus);
        }
        if ($userWithdrawalIds)
            (new ZoomMeetingService())->unAssignStudentsToVcrCViaClassIdAndUserIds($id, $userWithdrawalIds);
        if ($userActiveIds)
            (new ZoomMeetingService())->assignStudentsToVcrCViaClassIdAndUserIds($id, $userActiveIds);

        return true;
    }

    /**
     * @Author Edogawa Conan
     * @Date   Apr 07, 2022
     *
     * @param int|string $classAssignmentId
     * @param string     $status
     *
     * @return bool
     * @throws Exception
     */
    public function changeStatusEnrollToLms(int|string $classAssignmentId, string $status): bool
    {
        $lmsName = LMSService::getViaClassAssignmentId($classAssignmentId)?->name;

        // if ($lmsName == LmsSystemConstant::EDMENTUM)
        //     $this->changeStatusEnrollEdmentum($classAssignmentId, $status);
        if ($lmsName == LmsSystemConstant::AGILIX)
            $this->changeStatusEnrollAgilix($classAssignmentId, $status);

        return true;
    }

    /**
     * @throws Exception
     * @throws Throwable
     */
    public function updateStatusAssignFromClassesViaStudentId(int|string $studentId, object $request): bool
    {
        $studentSQL = (new UserService())->get($studentId)->userSql;
        $schoolId   = SchoolServiceProvider::$currentSchool->id;
        $status     = [
            StatusConstant::ACTIVE,
            StatusConstant::COMPLETED,
            StatusConstant::INACTIVE,
            StatusConstant::WITHDRAWAL
        ];
        $this->doValidate($request, [
            'assignments.*.class_id' => "required|exists:classes,id,school_id,$schoolId",
            'assignments.*.status'   => 'required|in:' . implode(',', $status)
        ]);

        foreach ($request->assignments as $assignment) {
            $classAssignment = $this->getViaUserIdAndClassId($studentSQL->id, $assignment['class_id']);
            if (!$classAssignment)
                continue;
            $assignmentStatus        = $assignment['status'];
            $classAssignment->status = $assignmentStatus;
            $classAssignment->save();

            $this->changeStatusEnrollToLms($classAssignment->id, $assignmentStatus);
            if ($assignment['status'] == StatusConstant::WITHDRAWAL)
                (new ZoomMeetingService())->unAssignStudentsToVcrCViaClassIdAndUserIds($assignment['class_id'],
                                                                                       [$studentSQL->id]);
            if ($assignment['status'] == StatusConstant::ACTIVE)
                (new ZoomMeetingService())->assignStudentsToVcrCViaClassIdAndUserIds($assignment['class_id'],
                                                                                     [$studentSQL->id]);
        }

        return true;
    }


}
