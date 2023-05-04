<?php

namespace App\Jobs\SyncClass;

use App\Models\EdmentumClass;
use App\Services\ClassAssignmentService;
use App\Services\CourseService;
use Exception;
use Illuminate\Support\Facades\Log;
use YaangVu\Constant\CodeConstant;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\Constant\SchoolConstant;
use YaangVu\Constant\StatusConstant;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Models\impl\UserSQL;

class SyncClassEdmentum extends SyncClass
{
    protected string $table   = 'lms_edmentum_classes';
    protected string $lmsName = LmsSystemConstant::EDMENTUM;

    public function __construct($schoolUuid = SchoolConstant::IGS)
    {
        parent::__construct($schoolUuid);
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        parent::handle();
    }

    public function getData(): mixed
    {
        return EdmentumClass::orderBy($this->jobName . '_at')
                            ->limit($this->limit)
                            ->get()
                            ->toBase();
    }

    /**
     * @param $data
     */
    public function sync($data): void
    {
        Log::info("$this->instance LMS $this->lmsName  Class to sync: lms_edmentum_classes id: $data->_id");

        // If you have no new data to continue
        if ($data->pulledat?->toDateTime() < $data->{$this->jobName . '_at'}?->toDateTime()) {
            $this->callback($data);

            return;
        }

        // Insert Or Update course to postgres
        $class                       = [];
        $class['name']               = trim($data->Name);
        $class[CodeConstant::EX_ID]  = trim((string)$data->ClassId);
        $class['course_external_id'] = trim((string)$data->ResourceNodeId);
        $class['edmentum_id']        = trim((string)$data->ClassId);
        $class['lms_id']             = $this->lms->id;
        $class['school_id']          = $this->school->id;
        $class['description']        = trim($data->Description);
        $class['status']             = $data->IsActive ? StatusConstant::ON_GOING : StatusConstant::PENDING;

        $course = (new CourseService())->getByLmsIdAndSchoolIdAndExId($class['lms_id'], $class['school_id'],
                                                                      $class['course_external_id']);

        if ($course) {
            $class['course_id'] = $course?->id;
        }
        $class['start_date']           = $data->StartDate;
        $class['end_date']             = $data->EndDate;
        $class['zone']                 = $data->ProgramId;
        $class['pulled_at']            = $data->pulledat ?? null;
        $class[$this->jobName . '_at'] = $data->{$this->jobName . '_at'} ?? null;

        Log::info($this->instance . ' ----- Sync to Sql Edmentum class: ', $class);

        try {
            // Sync Class data to SQL and NoSQL database
            $classNoSql = $this->_syncClass($class);

            // Sync Class assignment
            // $this->_syncClassAssignment($classNoSql->id, ClassAssignmentConstant::STUDENT, $data['Learners'] ?? []);
            // $this->_syncClassAssignment($classNoSql->id, ClassAssignmentConstant::PRIMARY_TEACHER,
            //                             $data['Teachers'] ?? []);

            // Update synced_at to mongodb
            $this->callback($data);
        } catch (Exception $exception) {
            Log::error($this->instance . ' ----- Sync course: ' . $data->Name . ' fail: ' . $exception->getMessage());
            Log::debug($exception);

            // Update synced_at to mongodb
            $this->callback($data, false);
        }
    }

    /**
     * @param       $classId
     * @param       $assignment
     * @param array $users
     */
    private function _syncClassAssignment($classId, $assignment, array $users)
    {
        if (!count($users))
            return;

        foreach ($users as $index => $user)
            $users[$index] = (string)$user;

        $uuids   = UserNoSQL::whereIn('external_id.edmentum', $users)->pluck('uuid');
        $userIds = UserSQL::whereIn('uuid', $uuids)->pluck('id');
        (new ClassAssignmentService())->sync($classId, $assignment, $userIds);
    }
}
