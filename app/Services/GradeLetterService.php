<?php


namespace App\Services;


use Illuminate\Database\Eloquent\Builder;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\GradeLetterSQL;

class GradeLetterService extends BaseService
{
    public function createModel(): void
    {
        $this->model = new GradeLetterSQL();
    }

    public function getGradeLetterByScoreAndGradeScaleId(float $score, int $gradeScaleId): mixed
    {
        $gradeScales = $this->model->where('grade_scale_id', $gradeScaleId)->orderBy('score', 'desc')->get();
        foreach ($gradeScales as $gradeScale) {
            if ($score >= $gradeScale->score)
                return $gradeScale;
        }

        return null;
    }

    /**
     * @Author Edogawa Conan
     * @Date   Sep 17, 2021
     *
     * @param     $score
     * @param int $classId
     *
     * @return GradeLetterSQL|Builder|null
     */
    function getViaScoreAndClassId($score, int $classId): GradeLetterSQL|Builder|null
    {
        return GradeLetterSQL::with([])
                             ->select('grade_letters.*')
                             ->join('grade_scales', 'grade_scales.id', '=', 'grade_letters.grade_scale_id')
                             ->join('subjects', 'subjects.grade_scale_id', '=', 'grade_scales.id')
                             ->join('classes', 'classes.subject_id', '=', 'subjects.id')
                             ->where('grade_letters.score', '<=', $score)
                             ->where('classes.id', $classId)
                             ->orderBy('grade_letters.score', 'DESC')
                             ->first();
    }
}
