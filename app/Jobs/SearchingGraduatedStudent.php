<?php

namespace App\Jobs;

use App\Services\GraduationCategorySubjectService;
use DB;
use Log;
use YaangVu\SisModel\App\Models\impl\GraduatedStudentSQL;
use YaangVu\SisModel\App\Models\impl\UserProgramSQL;
use YaangVu\SisModel\App\Models\impl\UserSQL;

class SearchingGraduatedStudent extends Job
{
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {

    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $userPrograms = UserProgramSQL::all();
        foreach ($userPrograms as $userProgram) {
            $userSql = UserSQL::whereId($userProgram->user_id)->first();

            if (!($userSql->uuid ?? null))
                continue;

            $userAcademicPlan = (new GraduationCategorySubjectService())->getUserAcademicPlan($userSql->uuid,
                                                                                              $userProgram->program_id);

            Log::info("[SearchingGraduatedStudent] user : $userSql->username have academic plan : ",
                      $userAcademicPlan['total']);

            if ($userAcademicPlan['total']['missing'] !== 0)
                continue;

            if (GraduatedStudentSQL::whereUserUuid($userSql->uuid)
                                   ->whereUserId($userSql->id)
                                   ->whereProgramId($userProgram->program_id)
                                   ->first())
                continue;

            DB::table('graduated_students')->insert(
                [
                    'user_id'    => $userSql->id,
                    'user_uuid'  => $userSql->uuid,
                    'program_id' => $userProgram->program_id
                ]
            );

            Log::info("[SearchingGraduatedStudent] success insert graduated_students with user id : $userSql->id , username : $userSql->username , program_id : $userProgram->program_id");
        }
    }
}
