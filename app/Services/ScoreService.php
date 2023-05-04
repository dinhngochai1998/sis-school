<?php


namespace App\Services;


use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use JetBrains\PhpStorm\ArrayShape;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\impl\ScoreSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;

class ScoreService extends BaseService
{
    public Model|Builder|ScoreSQL $model;

    public function createModel(): void
    {
        $this->model = new ScoreSQL();
    }

    public function isScorePassed(int $classId, int $userId): ?bool
    {
        $score = $this->model->where('class_id', $classId)->where('user_id', $userId)->first();

        return (($score->is_pass ?? null) == true);
    }

    /**
     * @Author yaangvu
     * @Date   Aug 06, 2021
     *
     * @param int|string $schoolId
     * @param int|string $lmsId
     * @param int|string $classExId
     * @param float|int  $rawScore
     *
     * @return array
     */
    #[ArrayShape(['class_id' => "\Illuminate\Database\Eloquent\HigherOrderBuilderProxy|int|mixed", 'grade_letter' => "null|string", 'grade_letter_id' => "int|null", 'real_weight' => "int|null", 'is_pass' => "bool"])]
    public function calculateBySchoolIdAndLmsIdAndClassExIdAndRowScore(int|string $schoolId,
                                                                       int|string $lmsId,
                                                                       int|string $classExId,
                                                                       float|int  $rawScore): array
    {
        $class = (new ClassService())->getByLmsIdAndExId($schoolId, $lmsId, $classExId);

        return $this->extracted($class, $rawScore);
    }

    /**
     * @Description
     *
     * @Author kyhoang
     * @Date   Jun 14, 2022
     *
     * @param int       $classId
     * @param float|int $rawScore
     *
     * @return array
     */
    #[ArrayShape(['class_id' => "mixed", 'grade_letter' => "mixed", 'grade_letter_id' => "mixed", 'is_pass' => "\bool|null", 'real_weight' => "mixed"])]
    public function calculateByClassIdAndRawScore(int $classId, float|int $rawScore): array
    {
        $class = ClassSql::whereId($classId)
                         ->with(['subject.gradeScale.gradeLetters' => function ($query) {
                             $query->orderBy('score');
                         }])
                         ->first();

        return $this->extracted($class, $rawScore);
    }

    /**
     * @Description Get the highest score by $userId and $classId
     *
     * @Author      yaangvu
     * @Date        Aug 25, 2021
     *
     * @param int $userId
     * @param int $classId
     *
     * @return Model|Builder|ScoreSQL|null
     */
    public function getHighestByUserIdAndClassId(int $userId, int $classId): Model|Builder|ScoreSQL|null
    {
        return $this->model->whereUserId($userId)
                           ->whereClassId($classId)
                           ->orderBy('score', 'desc')
                           ->first();
    }

    public function upsert(array $score): Model|Builder
    {
        $school = SchoolServiceProvider::$currentSchool;

        return $this->model
            ->updateOrCreate([
                                 'class_id' => $score['class_id'],
                                 'user_id'  => $score['user_id']
                             ], [
                                 'class_id'        => $score['class_id'],
                                 'user_id'         => $score['user_id'],
                                 'score'           => $score['score'],
                                 'lms_id'          => $score['lms_id'] ?? null,
                                 'school_id'       => $school->id,
                                 'grade_letter_id' => $score['grade_letter_id'] ?? null,
                                 'is_pass'         => $score['is_pass'] ?? null,
                                 'grade_letter'    => $score['grade_letter'] ?? null,
                                 'current_score'   => $score['current_score'] ?? null,
                                 'real_weight'     => $score['real_weight'] ?? null
                             ]);
    }

    /**
     * @Description
     *
     * @Author kyhoang
     * @Date   Jun 14, 2022
     *
     * @param Model|Builder|ClassSQL|null $class
     * @param float|int                   $rawScore
     *
     * @return array
     */
    #[ArrayShape(['class_id' => "mixed", 'grade_letter' => "mixed", 'grade_letter_id' => "mixed", 'is_pass' => "bool|null", 'real_weight' => "mixed"])]
    public function extracted(Model|Builder|ClassSQL|null $class, float|int $rawScore): array
    {
        foreach ($class?->subject?->gradeScale?->gradeLetters ?? [] as $gradeLetter) {
            if ($rawScore < $gradeLetter->score)
                break;

            $letterId = $gradeLetter->id;
            $letter   = $gradeLetter->letter;
        }

        return [
            'class_id'        => $class?->id,
            'grade_letter'    => $letter ?? null,
            'grade_letter_id' => $letterId ?? null,
            'is_pass'         => isset($class->subject->gradeScale->score_to_pass)
                ? $rawScore >= $class->subject->gradeScale->score_to_pass
                : null,
            'real_weight'     => $class?->subject?->weight
        ];
    }

    static function getViaUserIdAndClassId(int $userId, int $classId): Model|Builder|ScoreSQL|null
    {
        return ScoreSQL::whereClassId($classId)
                       ->whereUserId($userId)
                       ->first();
    }
}
