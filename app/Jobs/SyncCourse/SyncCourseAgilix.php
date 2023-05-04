<?php

namespace App\Jobs\SyncCourse;

use Exception;
use Illuminate\Support\Facades\Log;
use YaangVu\Constant\CodeConstant;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\Constant\SchoolConstant;
use YaangVu\SisModel\App\Models\MongoModel;

class SyncCourseAgilix extends SyncCourse
{
    public string    $table   = 'lms_agilix_courses';
    protected string $lmsName = LmsSystemConstant::AGILIX;

    public function __construct($schoolUuid = SchoolConstant::IGS)
    {
        parent::__construct($schoolUuid);
    }

    public function getData(): mixed
    {
        return MongoModel::from($this->table)
                         ->limit($this->limit)
                         ->orderBy($this->jobName . '_at')
                         ->get()
                         ->toBase();
    }

    public function sync($data): void
    {
        Log::info("$this->instance LMS $this->lmsName  Course to sync: lms_agilix_courses id: $data->_id");

        // If you have no new data to continue
        if ($data->pulledat?->toDateTime() < $data->{$this->jobName . '_at'}?->toDateTime()) {
            $this->callback($data);

            return;
        }
        // Insert Or Update course to postgres
        $course                      = [];
        $course['name']              = trim($data->title . ' - ' . ((string)$data->id));
        $course[CodeConstant::EX_ID] = trim((string)$data->id);
        $course['agilix_id']         = trim((string)$data->id);

        // Insert course into MongoDb courses table
        $course['lms_name']    = $this->lms->name;
        $course['school_name'] = $this->school->name;
        $course                = array_merge($course, $data->toArray());

        try {
            $this->_syncCourse($course);

            // Update synced_at to mongodb
            $this->callback($data);
        } catch (Exception $exception) {
            Log::error($this->instance . ' Sync course: ' . $data->ResourceName . ' fail: ' . $exception->getMessage());
            Log::debug($exception);

            // Update synced_at to mongodb
            $this->callback($data, false);
        }
    }
}
