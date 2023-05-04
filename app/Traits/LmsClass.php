<?php
/**
 * @Author yaangvu
 * @Date   Aug 30, 2021
 */

namespace App\Traits;

use JetBrains\PhpStorm\ArrayShape;
use YaangVu\Constant\CodeConstant;
use YaangVu\Constant\GradeConstant;
use YaangVu\Constant\StatusConstant;
use YaangVu\SisModel\App\Models\impl\ClassAssignmentSQL;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;

trait LmsClass
{
    use LmsUser;

    /**
     * @Author Edogawa Conan
     * @Date   Oct 20, 2021
     *
     * @param int $classId
     *
     * @return array
     */
    #[ArrayShape(['class' => "array", 'uuid' => "mixed", 'schoolId' => "null|string"])]
    public function mappingLMSData(int $classId): array
    {
        $class = ClassSQL::whereId($classId)
                         ->with('graduationCategories.programs', 'course')
                         ->first();

        return [
            'class'    => [
                'id'          => $class->external_id ?? null,
                'zoneId'      => $class->zone ?? null,
                'courseId'    => $class->course ? $class->course['external_id'] : null,
                'name'        => $class->name ?? null,
                'description' => $class->description ?? null,
                'startDate'   => $class->start_date ?? null,
                'endDate'     => $class->end_date ?? null,
                'term'        => $class->terms?->name,
                'isActive'    => !($class->status == StatusConstant::PENDING)
            ],
            'uuid'     => $class->uuid ?? null,
            'schoolId' => SchoolServiceProvider::$currentSchool->uuid
        ];
    }

    #[ArrayShape(["schoolId" => "null|string", "enrollments" => "object[]"])]
    public function mappingAssignData(int $classAssignmentId, string $role, string $lms): array
    {
        $classAssignment = ClassAssignmentSQL::whereId($classAssignmentId)
                                             ->with('users', 'classes')
                                             ->first();

        $userNoSql = UserNoSQL::whereUsername($classAssignment->users?->username)->first();

        if (in_array($userNoSql->grade ?? null, GradeConstant::GRADE_NOT_SYNC_STUDENT))
            $userNoSql->grade = null;

        $user = $this->lmsUser($userNoSql);

        return [
            "schoolId"    => SchoolServiceProvider::$currentSchool->uuid,
            "enrollments" => [
                (object)[
                    "userId"       => $userNoSql?->external_id[$lms] ?? null, //external id of user
                    "classId"      => $classAssignment->classes?->{CodeConstant::EX_ID} ?? null,  //external id of class
                    "role"         => $role, //Agilix only, possible values: Student | Teacher,
                    "assignmentId" => $classAssignmentId,
                    "user"         => $user
                ]
            ]
        ];
    }

    #[ArrayShape(["schoolId" => "null|string", "enrollments" => "object[]"])]
    public function mappingUnAssignData(int $classAssignmentId, string $lms): array
    {
        $classAssignment = ClassAssignmentSQL::whereId($classAssignmentId)
                                             ->with('users', 'classes')
                                             ->first();

        $userNoSql = UserNoSQL::whereUsername($classAssignment->users?->username)->first();

        return [
            "schoolId"    => SchoolServiceProvider::$currentSchool->uuid,
            "enrollments" => [
                (object)[
                    "enrollmentId" => $classAssignment->{CodeConstant::EX_ID} ?? null,
                    "classId"      => $classAssignment->classes->{CodeConstant::EX_ID} ?? null, //external id of class
                    "userId"       => $userNoSql?->external_id[$lms] ?? null, //external id of user
                ]
            ]
        ];
    }

    #[ArrayShape(["schoolId" => "null|string", "enrollments" => "object[]"])]
    public function mappingUpdateStatusEnroll(int $classAssignmentId, string $lms, string $status): array
    {
        $classAssignment = ClassAssignmentSQL::whereId($classAssignmentId)
                                             ->with('users', 'classes')
                                             ->first();

        $userNoSql = UserNoSQL::whereUsername($classAssignment->users?->username)->first();

        return [
            "schoolId"    => SchoolServiceProvider::$currentSchool->uuid,
            "enrollments" => [
                (object)[
                    "enrollmentId" => $classAssignment->{CodeConstant::EX_ID} ?? null,
                    "classId"      => $classAssignment->classes->{CodeConstant::EX_ID} ?? null, //external id of class
                    "userId"       => $userNoSql?->external_id[$lms] ?? null, //external id of user,
                    "status"       => strtolower($status)
                ]
            ]
        ];
    }
}
