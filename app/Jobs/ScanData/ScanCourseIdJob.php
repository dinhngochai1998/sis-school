<?php

namespace App\Jobs\ScanData;

use App\Jobs\Job;
use App\Models\EdmentumClass;
use App\Models\EdmentumResourceNode;
use Illuminate\Support\Collection;
use Log;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\impl\CourseSQL;

class ScanCourseIdJob extends Job
{
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $classes = $this->_getClassesWithoutCourseId();
        foreach ($classes as $class) {
            switch ($class->lms_id) {
                case 1: //Edmentum
                    $class->course_id = $this->_getEdementumCourseId($class);
                    break;
                case 2: //Agillix
                    $class->course_id = $this->_getAgillixCourseId($class);
                    break;
                default:
                    Log::info('Scan course_id fail, class_sql_id: ' . $class->id);
                    break;
            }

            Log::info('Scan course_id for class_sql_id: ' . $class->id);
            $class->save();
        }
    }

    /**
     * @Description
     *
     * @Author yaangvu
     * @Date   Jan 26, 2022
     *
     * @return ClassSQL[]|Collection
     */
    private function _getClassesWithoutCourseId(): array|Collection
    {
        return ClassSQL::whereNull('course_id')
                       ->whereNotNull('lms_id')
                       ->where('lms_id', '!=', 3) //not include SIS lms
                       ->limit(500)
                       ->get();
    }

    private function _getEdementumCourseId(ClassSQL $class): int|string|null
    {
        $edmentumClass = EdmentumClass::where('ClassId', '=', (int)$class->external_id)->first();
        if (!$edmentumClass)
            return null;
        $edmentumCourse = EdmentumResourceNode::where('ResourceNodeId', '=', (int)$edmentumClass->ResourceNodeId)
                                              ->first();
        if (!$edmentumCourse)
            return null;
        $course = CourseSQL::whereExternalId($edmentumCourse->ResourceNodeId)->whereLmsId(1)->first();

        return $course->id ?? null;
    }

    private function _getAgillixCourseId(ClassSQL $class): int|string|null
    {
        $course = CourseSQL::whereExternalId($class->external_id)->whereLmsId(2)->first();

        return $course->id ?? null;
    }
}
