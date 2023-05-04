<?php
/**
 * @Author Edogawa Conan
 * @Date   Oct 22, 2021
 */

namespace App\Jobs\SyncCourse;

use App\Jobs\SyncDataJob;
use Faker\Provider\Uuid;
use Illuminate\Support\Facades\Log;
use YaangVu\Constant\CodeConstant;
use YaangVu\SisModel\App\Models\impl\CourseSQL;
use YaangVu\SisModel\App\Models\MongoModel;

abstract class SyncCourse extends SyncDataJob
{
    public string $jobName = 'sync_course';
    public int    $limit   = 500;

    public function handle()
    {
        parent::handle();

        Log::info($this->instance . " ----- Started sync LMS $this->lmsName courses for school: $this->school");
        $lmsData = $this->getData();
        foreach ($lmsData as $data)
            $this->sync($data);
        Log::info($this->instance . " ----- Ended sync LMS $this->lmsName courses for school: $this->school");
    }

    /**
     * @param array $course
     *
     * @return CourseSQL
     */
    protected function _syncCourse(array $course): CourseSQL
    {
        $course['lms_id']           = $this->lms->id;
        $course['school_id']        = $this->school->id;
        $course[CodeConstant::UUID] = CourseSQL::where(CodeConstant::EX_ID, $course[CodeConstant::EX_ID])
                                               ->where('lms_id', $this->lms->id)
                                               ->where('school_id', $this->school->id)
                                               ->first()->uuid ?? Uuid::uuid();

        $attributes = [
            'lms_id'            => $course['lms_id'],
            'school_id'         => $course['school_id'],
            CodeConstant::EX_ID => $course[CodeConstant::EX_ID]
        ];
        // Insert course into Postgres courses table
        $courseSql = CourseSQL::query()->updateOrCreate($attributes, $course);

        (new MongoModel())->setTable('courses')->updateOrCreate($attributes, $course);

        return $courseSql;
    }
}
