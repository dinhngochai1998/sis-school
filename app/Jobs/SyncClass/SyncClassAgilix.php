<?php


namespace App\Jobs\SyncClass;


use App\Services\CourseService;
use Exception;
use Faker\Provider\Uuid;
use Illuminate\Support\Facades\Log;
use YaangVu\Constant\CodeConstant;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\Constant\SchoolConstant;
use YaangVu\Constant\StatusConstant;
use YaangVu\SisModel\App\Models\impl\CourseSQL;
use YaangVu\SisModel\App\Models\MongoModel;

class SyncClassAgilix extends SyncClass
{
    protected string $table            = 'lms_agilix_courses';
    protected string $tableRole        = 'lms_agilix_roles';
    protected string $tableEnrollment  = 'lms_agilix_enrollments';
    protected string $lmsName          = LmsSystemConstant::AGILIX;
    private array    $allowedRootRoles = ['Student', 'Teacher'];

    public function __construct($schoolUuid = SchoolConstant::IGS)
    {
        parent::__construct($schoolUuid);
    }

    public function handle()
    {
        $roles = $this->getRootRole();
        foreach ($roles as $role) {
            $this->rootRoleIds[]        = $role->id;
            $this->rootRoles[$role->id] = $role->name;
        }

        parent::handle();
    }

    private function getRootRole(): mixed
    {
        return MongoModel::from($this->tableRole)
                         ->whereIn('name', $this->allowedRootRoles)
                         ->where('domainid', '1')
                         ->where('domainname', 'Root')
                         ->get();
    }

    /**
     * @inheritDoc
     */
    public function getData(): mixed
    {
        return MongoModel::from($this->table)
            // ->where('id', '=', '172013336')
                         ->orderBy($this->jobName . '_at')
                         ->limit($this->limit)
                         ->get()
                         ->toBase();
    }

    /**
     * @inheritDoc
     */
    public function sync($data): void
    {
        Log::info("$this->instance LMS $this->lmsName  Class to sync: lms_agilix_courses id: $data->_id");

        // If you have no new data to continue
        if ($data->pulledat?->toDateTime() < $data->{$this->jobName . '_at'}?->toDateTime()) {
            $this->callback($data);

            return;
        }

        // Insert Or Update course to postgres
        $class                      = [];
        $class['name']              = trim($data->title);
        $class[CodeConstant::EX_ID] = trim((string)$data->id);
        $class['agilix_id']         = trim((string)$data->id);
        $class['lms_id']            = $this->lms->id;
        $class['school_id']         = $this->school->id;
        $class['status']            = ($data->flags == '0') ? StatusConstant::ON_GOING : StatusConstant::PENDING;
        $class[CodeConstant::UUID]  = CourseSQL::where(CodeConstant::EX_ID, $class[CodeConstant::EX_ID])
                                               ->where('lms_id', $class['lms_id'])
                                               ->where('school_id', $class['school_id'])
                                               ->first()->uuid ?? Uuid::uuid();

        // $courseSQL = $this->_syncCourse($course);
        // $course['course_id']  = $courseSQL->id;
        $course = (new CourseService())->getByLmsIdAndSchoolIdAndExId($class['lms_id'], $class['school_id'],
                                                                      $class[CodeConstant::EX_ID]);
        if ($course) {
            $class['course_id'] = $course?->id;
        }

        $class['start_date']           = $data->startdate;
        $class['end_date']             = $data->enddate;
        $class['zone']                 = $data->domainid;
        $class['pulled_at']            = $data->pulledat ?? null;
        $class[$this->jobName . '_at'] = $data->{$this->jobName . '_at'} ?? null;
        Log::info($this->instance . ' ----- Sync to Sql Agilix class: ', $class);

        try {
            // Sync Class data to SQL and NoSQL database
            $courseNoSql = $this->_syncClass($class);

            // Sync Class assignment
            // $this->_syncClassAssignment($courseNoSql->id, $course[CodeConstant::EX_ID]);

            // Update synced_at to mongodb
            $this->callback($data);
        } catch (Exception $exception) {
            Log::error($this->instance . ' ----- Sync course: ' . $data->Name . ' fail: ' . $exception->getMessage());
            Log::debug($exception);

            // Update synced_at to mongodb
            $this->callback($data, false);
        }
    }

    // private function _syncClassAssignment($courseId, $externalCourseId)
    // {
    //     ClassAssignmentSQL::whereClassId($courseId)->forceDelete();
    //     foreach ($this->rootRoleIds as $roleId) {
    //         $enrolmentUserIds = $this->getEnrolmentUsers($externalCourseId, $roleId);
    //         // If no user with role enrolled in course -> next
    //         if (!$enrolmentUserIds) continue;
    //
    //         foreach ($enrolmentUserIds as $index => $userId)
    //             $enrolmentUserIds[$index] = (string)$userId;
    //
    //         $uuids = UserNoSQL::whereIn('external_id.agilix', $enrolmentUserIds)->pluck('uuid')->toArray();
    //         if (!$uuids) continue;
    //         $userIds = UserSQL::whereIn('uuid', $uuids)->pluck('id')->toArray();
    //         if (!$userIds) continue;
    //         (new ClassAssignmentService())->sync($courseId, $this->getClassRoleMapping($this->rootRoles[$roleId]),
    //                                              $userIds);
    //     }
    // }
    //
    // private function getEnrolmentUsers($courseId, $roleId)
    // {
    //     return MongoModel::from($this->tableEnrollment)
    //                      ->where('courseid', $courseId)
    //                      ->where('roleid', $roleId)
    //                      ->pluck('userid')->toArray();
    // }
    //
    // public function getClassRoleMapping($flvsRoleName): string
    // {
    //     return match ($flvsRoleName) {
    //         'Student' => ClassAssignmentConstant::STUDENT,
    //         'Teacher' => ClassAssignmentConstant::PRIMARY_TEACHER,
    //         default => '',
    //     };
    // }
}
