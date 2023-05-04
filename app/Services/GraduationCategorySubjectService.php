<?php


namespace App\Services;


use JetBrains\PhpStorm\ArrayShape;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\ClassAssignmentSQL;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\impl\GraduationCategorySQL;
use YaangVu\SisModel\App\Models\impl\GraduationCategorySubjectSQL;
use YaangVu\SisModel\App\Models\impl\ProgramGraduationCategorySQL;
use YaangVu\SisModel\App\Models\impl\SubjectSQL;
use YaangVu\SisModel\App\Models\impl\UserProgramSQL;
use YaangVu\SisModel\App\Models\impl\UserSQL;

class GraduationCategorySubjectService extends BaseService
{
    private object $scoreService;

    public function __construct()
    {
        $this->scoreService = new ScoreService();
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    function createModel(): void
    {
        $this->model = new GraduationCategorySubjectSQL();
    }

    #[ArrayShape(['list' => "array", 'total' => "int[]"])]
    public function getUserAcademicPlan(string $uuId, int $programId): array
    {
        $graduationCategories = [];
        $total                = ['needed' => 0, 'earned' => 0, 'missing' => 0];
        $user                 = UserSQL::whereUuid($uuId)->first();

        if (($user->id ?? null) != true) return ['list' => $graduationCategories, 'total' => $total];

        // Get user programs
        $userProgram = UserProgramSQL::where('user_id', $user->id)->where('program_id', $programId)->first();
        if (($userProgram->id ?? null) != true) return ['list' => $graduationCategories, 'total' => $total];

        // Get program graduation category
        $programGraduationCats = ProgramGraduationCategorySQL::where('program_id', $userProgram->program_id)->get();
        foreach ($programGraduationCats as $programGraduationCat) {
            $rowData = [];
            // Get graduation category
            $graduationCat = GraduationCategorySQL::where('id', $programGraduationCat->graduation_category_id)->first();
            if (($graduationCat->id ?? null) != true) continue;

            $rowData['name']   = $graduationCat->name;
            $rowData['needed'] = $programGraduationCat->credit;
            $rowData['earned'] = 0;

            $total['needed'] += $rowData['needed'];

            // Get class user assigned
            $classAssignments = ClassAssignmentSQL::where('user_id', $user->id)->get();
            $assignedClass    = [];
            foreach ($classAssignments as $classAssignment) {
                $assignedClass[] = $classAssignment->class_id;
            }

            if (!$assignedClass) {
                $total['missing']       += $programGraduationCat->credit;
                $rowData['missing']     = $programGraduationCat->credit;
                $graduationCategories[] = $rowData;

                continue;
            }

            // Get list subject in graduation
            $graduationCatSubjects = GraduationCategorySubjectSQL::where('graduation_category_id', $graduationCat->id)
                                                                 ->get();
            foreach ($graduationCatSubjects as $graduationCatSubject) {
                // Check score to pass
                $classes = ClassSQL::where('subject_id', $graduationCatSubject->subject_id)
                                   ->whereIn('id', $assignedClass)
                                   ->get();

                foreach ($classes as $class) {
                    if((new ClassService())->isClassConcluded($class->id) && $this->scoreService->isScorePassed($class->id, $user->id)) {
                            $subject = SubjectSQL::where('id', $graduationCatSubject->subject_id)->first();
                            if (($subject->id ?? null) != true) continue;

                            $rowData['earned'] += $subject->credit;
                            break;
                    }
                }
            }

            $missing            = $rowData['needed'] - $rowData['earned'];
            $rowData['missing'] = $missing > 0 ? $missing : 0;
            $total['missing']   += $rowData['missing'];
            $total['earned']    += $rowData['earned'];

            $graduationCategories[] = $rowData;
        }

        return ['list' => $graduationCategories, 'total' => $total];
    }
}
