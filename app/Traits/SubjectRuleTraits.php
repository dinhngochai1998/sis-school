<?php


namespace App\Traits;


use App\Services\ClassService;
use App\Services\TermService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use YaangVu\Constant\ClassAssignmentConstant;
use YaangVu\SisModel\App\Models\impl\ClassAssignmentSQL;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\impl\ScoreSQL;
use YaangVu\SisModel\App\Models\impl\TermSQL;

trait SubjectRuleTraits
{
    public function isStudentPassSubject(int $classId, int|string $subjectId, int|string $studentId): bool
    {
        $class = ClassService::getViaIdAndSubjectId($classId, $subjectId);

        return ScoreSQL::whereClassId($class?->id)->whereUserId($studentId)->first()?->is_pass ?? false;
    }

    public function isBefore(int $classId, int|string $subjectId, int|string $relevanceSubjectId,
                             int|string $studentId): bool
    {
        $relevanceClass = $this->_getRelevanceClassViaSubjectIdAndUserId($relevanceSubjectId, $studentId);
        $scoreRelevance = ScoreSQL::whereClassId($relevanceClass?->id)->whereUserId($studentId)->first();
        if (!$scoreRelevance && !($scoreRelevance?->is_pass ?? false))
            return false;

        return !$this->isStudentPassSubject($classId, $subjectId, $studentId);
    }

    public function isSameTeacher(int $classId, int|string $relevanceSubjectId,
                                  int|string $studentId): bool
    {
        $class          = (new ClassService())->get($classId);
        $relevanceClass = $this->_getRelevanceClassViaSubjectIdAndUserId($relevanceSubjectId, $studentId);
        if (!$relevanceClass) {
            $relevanceClass = ClassService::getViaIdAndSubjectId($classId, $relevanceSubjectId);
            $score          = ScoreSQL::whereClassId($relevanceClass?->id)->whereUserId($studentId)->first();
            if (!$score && !($score->is_pass ?? false))
                return true;
        }
        $teacherRelevance = ClassAssignmentSQL::whereAssignment(ClassAssignmentConstant::PRIMARY_TEACHER)
                                              ->whereClassId($relevanceClass?->id)
                                              ->first();

        $classAssignment = ClassAssignmentSQL::whereUserId($teacherRelevance?->user_id)
                                             ->whereIn('class_id', [$class?->id, $relevanceClass?->id])
                                             ->whereAssignment(ClassAssignmentConstant::PRIMARY_TEACHER)
                                             ->get();

        return count($classAssignment) > 1;
    }

    public function isConsecutive(int $classId, int|string $relevanceSubjectId,
                                  int|string $studentId): bool
    {
        // get class A via subject A
        $class = (new ClassService())->get($classId);
        // get class B via subject B
        $relevanceClass = $this->_getRelevanceClassViaSubjectIdAndUserId($relevanceSubjectId, $studentId);
        // score of B
        $scoreRelevance = ScoreSQL::whereClassId($relevanceClass?->id)->whereUserId($studentId)->first();
        // check student passes subject B or not
        if (!$scoreRelevance && !($scoreRelevance->is_pass ?? false)) {
            return true;
        }
        // get term of subject A
        $term = (new TermService())->get($class?->term_id);
        // get term of subject B
        $termRelevance = (new TermService())->get($class?->term_id);
        // get term consecutive by start date of term of subject B
        $termConsecutive = TermSQL::whereId($relevanceClass?->term_id)
                                  ->where('start_date', '<=', $termRelevance->start_date)
                                  ->orderByDesc('start_date')
                                  ->first();

        return $termConsecutive?->id === $term->id;
    }

    public function isSameTerm(int $classId, int|string $relevanceSubjectId,
                               int $studentId): bool
    {
        $relevanceClass = $this->_getRelevanceClassViaSubjectIdAndUserId($relevanceSubjectId, $studentId);

        if (!$relevanceClass)
            return false;

        return (new ClassService())->get($classId)->term_id === $relevanceClass?->term_id;
    }

    private function _getRelevanceClassViaSubjectIdAndUserId(int $subjectId,
                                                             int $userId): Model|Builder|ClassSQL|null
    {
        return ClassSQL::whereSubjectId($subjectId)
                       ->select('classes.*')
                       ->join('class_assignments', 'class_assignments.class_id', '=', 'classes.id')
                       ->where('class_assignments.user_id', $userId)
                       ->where('class_assignments.assignment', ClassAssignmentConstant::STUDENT)
                       ->first();
    }
}
