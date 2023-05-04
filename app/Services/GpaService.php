<?php
/**
 * @Author yaangvu
 * @Date   Aug 19, 2021
 */

namespace App\Services;

use App\Helpers\RabbitMQHelper;
use Exception;
use Faker\Provider\Uuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\NoReturn;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use YaangVu\Constant\StatusConstant;
use YaangVu\Constant\SubjectConstant;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\CpaSQL;
use YaangVu\SisModel\App\Models\impl\GpaSQL;
use YaangVu\SisModel\App\Models\impl\GradeSQL;
use YaangVu\SisModel\App\Models\impl\UserSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;

class GpaService extends BaseService
{
    use RabbitMQHelper;


    protected const MAX_CPA_BONUS_POINTS = 4.80;
    public Model|Builder|GpaSQL $model;
    protected string $attendancePresentView = 'attendance_present_view';
    protected string $scoreView             = 'score_view';

    function createModel(): void
    {
        $this->model = new GpaSQL();
    }

    /**
     * @Author yaangvu
     * @Date   Aug 19, 2021
     *
     * @param int|null   $termId
     * @param int|null   $gradeId
     * @param float|null $gpa
     *
     * @return int
     */
    function countByTermIdAndGradeIdAndGpaPoint(?int $termId = null, ?int $gradeId = null, ?float $gpa = null): int
    {
        if ($termId)
            return $this->model
                ->whereGradeId($gradeId)
                ->whereSchoolId(SchoolServiceProvider::$currentSchool->id)
                ->when($gpa, function (Builder|GpaSQL $query) use ($termId, $gpa) {
                    return $query->whereTermId($termId)->whereGpa($gpa);
                })
                ->count();
        else
            return $this->model
                ->whereGradeId($gradeId)
                ->whereSchoolId(SchoolServiceProvider::$currentSchool->id)
                ->when($gpa, function (Builder|GpaSQL $query) use ($gpa) {
                    return $query->whereCpa($gpa);
                })
                ->count();
    }

    /**
     * @param object $request
     *
     * @return array
     *
     * @throws Exception
     */
    #[ArrayShape(['message' => "string"])]
    public function calculateGpaScore(object $request): array
    {
        try {
            $payload = [
                'term_id'    => $request->term_id ?? null,
                'program_id' => $request->program_id ?? null,
                'school_id'  => $request->school_id ?? null
            ];

            $this->pushToExchange($payload, 'CALCULATE_GPA_SCORE', AMQPExchangeType::DIRECT, 'gpa');

            return ['message' => "Send successfully!"];

        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }

    }

    /**
     * @param object|null $data
     *
     * @return void
     * @throws \Throwable
     */
    #[NoReturn]
    public function calculateScore(?object $data = null): void
    {
        $termId    = $data->term_id ?? null;
        $programId = $data->program_id ?? null;
        $schoolId  = $data->school_id ?? null;
        $scoresView = $this->_calculateScoresView($termId, $programId, $schoolId);
        $grouped    = $scoresView->groupBy(['program_id', 'user_id']);

        $scores = [];
        foreach ($grouped as $programIds => $programs) {
            if (empty($programIds)) continue;
            $studentsRank       = [];
            $ranks              = [];
            $userIds            = $programs->keys()->toArray();
            $userIdsStudyStatus = $this->_checkStudyStatusUsers($userIds);

            foreach ($programs as $userId => $users) {
                $groupByTerms          = $users->groupBy('term_id');
                $scoresGpa             = $this->_calculateScoresGpa($programIds, $userId, $groupByTerms);
                $studentsRank[$userId] = $scoresUser = $this->_calculateScoresCpa($scoresGpa, $userIdsStudyStatus);

                foreach ($scoresUser as $termId => $score) {
                    if (empty($score['grade_id'])) continue;
                    $gpa_wBonus_point
                                                                           = empty($score['gpa_wBonus_point']) ? 0.0 : $score['gpa_wBonus_point'];
                    $ranks[$termId][$score['grade_id']][$gpa_wBonus_point] = $gpa_wBonus_point;
                }
            }

            // Calculate rank
            foreach ($ranks as $termID => $grades) {
                foreach ($grades as $gradeId => $grouped_wBonus_point) {
                    krsort($grouped_wBonus_point);
                    $rank = 1;
                    foreach ($grouped_wBonus_point as $index => $point) {
                        $ranks[$termID][$gradeId][$point] = $rank++;
                    }
                }
            }

            foreach ($studentsRank as $userId => $student) {
                foreach ($student as $termId => $score) {
                    if (empty($score['grade_id'])) continue;
                    $gpa_wBonus_point = empty($score['gpa_wBonus_point']) ? 0.0 : $score['gpa_wBonus_point'];
                    if (!isset($ranks[$termId][$score['grade_id']][$gpa_wBonus_point])) continue;
                    $rankUser = $ranks[$termId][$score['grade_id']][$gpa_wBonus_point];
                    $scores[] = [
                        'created_by'           => $score['created_by'] ?? null,
                        'uuid'                 => $score['uuid'] ?? null,
                        'user_id'              => $score['user_id'] ?? null,
                        'term_id'              => $score['term_id'] ?? null,
                        'earned_credit'        => $score['earned_credit'] ?? null,
                        'learned_credit'       => $score['learned_credit'] ?? null,
                        'bonus_gpa'            => $score['bonus_gpa'] ?? null,
                        'gpa'                  => $score['gpa'] ?? null,
                        'gpa_bonus_point'      => $score['gpa_wBonus_point'] ?? null,
                        'rank'                 => $rankUser ?? null,
                        'school_id'            => $score['school_id'] ?? null,
                        'grade_id'             => $score['grade_id'] ?? null,
                        'program_id'           => $score['program_id'] ?? null,
                        'cpa'                  => $score['cpa'] ?? null,
                        'bonus_cpa'            => $score['bonus_cpa'] ?? null,
                        'total_learned_credit' => $score['total_learned_credit'] ?? null,
                        'total_earned_credit'  => $score['total_earned_credit'] ?? null,
                        'is_studying'          => $score['is_studying'] ?? null,
                        'gpa_unweighted'       => $score['gpa_unweighted'] ?? null
                    ];
                }
            }
        }

        \Log::info('Data Scores Users', $scores);
        DB::beginTransaction();
        try {
            if ($scores)
                foreach ($scores as $index => $score) {
                    if(empty($score['term_id'])) continue;
                    GpaSQL::updateOrCreate([
                                               'term_id' => $score['term_id'] ?? null,
                                               'user_id' => $score['user_id'] ?? null
                                           ], $score);
                    $this->_createDataCpa($score);
                }
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     *
     * @param int|null $termId
     * @param int|null $programId
     * @param int|null $schoolId
     *
     * @return Collection
     */
    private
    function _calculateScoresView(?int $termId, ?int $programId, ?int $schoolId): Collection
    {
        return DB::table($this->scoreView)
                 ->where('status', StatusConstant::CONCLUDED)
                 ->when($termId, function ($query) use ($termId) {
                     return $query->where('term_id', $termId);
                 })
                 ->when($programId, function ($query) use ($programId) {
                     return $query->where('program_id', $programId);
                 })
                 ->when($schoolId, function ($query) use ($schoolId) {
                     return $query->where('school_id', $schoolId);
                 })->orderBy('term_start_date', 'ASC')->get();
    }

    private function _checkStudyStatusUsers(?array $userIds): array
    {
        if (!$userIds)
            return [];

        try {
            $students = UserSQL::with('studentsNoSql')->whereIn('id', $userIds)->get();
            $uuids    = [];
            foreach ($students as $student) {
                if (!$student->studentsNoSql) continue;
                $uuids[] = $student->id;
            }

            return $uuids;
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @param int|null        $program_id
     * @param string|int|null $userId
     * @param object          $groupByTerms
     *
     * @return array
     */
    private function _calculateScoresGpa(?int   $program_id, null|string|int $userId,
                                         object $groupByTerms): array
    {
        if (!$groupByTerms)
            return [];

        $scoresGpa = [];
        $gradeId   = $this->_getGradeUser($userId)?->id;
        $schoolId  = (new ProgramService())->get($program_id)?->school_id;
        $cpa       = [];
        foreach ($groupByTerms as $termId => $term) {
            $learnedCredit = 0;
            $earnedCredit  = 0;
            $totalBonusGpa = 0;
            foreach ($term as $class) {
                if ($class->is_pass) {
                    $earnedCredit += $class->credit;
                }
                $learnedCredit += $class->credit;
                if ($class->subject_type == SubjectConstant::HONORS) {
                    $totalBonusGpa += $class->extra_point_honor;
                } elseif ($class->subject_type == SubjectConstant::ADVANCEDPLACEMENT) {
                    $totalBonusGpa += $class->extra_point_advanced;
                } else {
                    $totalBonusGpa += $class->bonus_point = 0;
                }

                $cpa[$class->subject_name][] = $class;
            }

            $totalWeight   = $term->where('is_calculate_gpa', true)->sum('weight');
            $earnedGpa     = $term->where('is_calculate_gpa', true)->sum(function ($class) {
                return $class->gpa * $class->weight;
            });

            $gpa           = round($earnedGpa / ($totalWeight == 0 ? 1 : $totalWeight), 2);
            $gpaBonusPoint = ($gpa + $totalBonusGpa);

            if ($gpaBonusPoint >= self::MAX_CPA_BONUS_POINTS) {
                $gpaBonusPoint = self::MAX_CPA_BONUS_POINTS;
            }

            $scoresGpa[$termId] = [
                'user_id'          => $userId,
                'term_id'          => $termId,
                'earned_credit'    => $earnedCredit,
                'learned_credit'   => $learnedCredit,
                'program_id'       => $program_id,
                'bonus_gpa'        => $totalBonusGpa,
                // 'earnedGpa'      => $earnedGpa,
                // 'totalWeight'    => $totalWeight,
                'gpa'              => $gpa,
                'gpa_wBonus_point' => $gpaBonusPoint,
                'school_id'        => $schoolId,
                'grade_id'         => $gradeId,
                'created_by'       => self::currentUser()?->id ?? null,
                'cpa'              => $cpa
            ];

        }

        return $scoresGpa;
    }

    private
    function _getGradeUser(string $id): Model|Builder|UserSQL|null
    {
        try {
            $userSQL   = UserSQL::whereId($id)->first();
            $gradeName = (new UserService())->getByUuid($userSQL->uuid)?->grade;

            return GradeSQL::whereName($gradeName)->first();

        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @param array|null $scoresGpa
     * @param array|null $userIdsStudyStatus
     *
     * @return array
     */
    private function _calculateScoresCpa(?array $scoresGpa, ?array $userIdsStudyStatus): array
    {
        if (!$scoresGpa) {
            Log::info("Information about Calculate scores cpa: scoresGpa is null", $scoresGpa);

            return [];
        }

        $scoresCpa = [];
        foreach ($scoresGpa as $termId => $gpa) {
            $totalBonusCpa = $totalEarnedCredit = $totalLearnedCredit = 0;
            $totalWeight = $totalCpa = $totalGpaUnweighted = $countGpaUnweighted  = 0;
                foreach ($gpa['cpa'] as $index => $cpaStudents) {
                    $countSubject    = count($cpaStudents);
                    $collectSubjects = collect($cpaStudents)->sortBy('term_start_date');
                    if ($countSubject > 1) {
                        foreach ($collectSubjects as $subject) {
                            $collectSubjects[0]->real_weight = 0;
                            $collectSubjects[0]->weight      = 0;
                            $collectSubjects[0]->gpa         = 0;
                            if (empty($subject->weight)) continue;

                            if ($subject->is_pass) {
                                $totalEarnedCredit += $subject->credit;
                            }
                            if ($subject->is_calculate_gpa) {
                                $totalWeight        += $subject->weight;
                                $totalCpa           += $subject->gpa * $subject->weight;
                                $totalGpaUnweighted += $subject->gpa;
                                $countGpaUnweighted++;

                                if ($subject->subject_type == SubjectConstant::HONORS) {
                                    $totalBonusCpa += $subject->extra_point_honor;
                                } elseif ($subject->subject_type == SubjectConstant::ADVANCEDPLACEMENT) {
                                    $totalBonusCpa += $subject->extra_point_advanced;
                                } else {
                                    $totalBonusCpa += $subject->bonus_point = 0;
                                }
                            }
                        }

                    } else {
                        foreach ($collectSubjects as $subject) {
                            if (empty($subject->weight)) continue;

                            if ($subject->is_pass) {
                                $totalEarnedCredit += $subject->credit;
                            }
                            if ($subject->is_calculate_gpa) {
                                $totalWeight        += $subject->weight;
                                $totalCpa           += $subject->gpa * $subject->weight;
                                $totalGpaUnweighted += $subject->gpa;
                                $countGpaUnweighted++;

                                if ($subject->subject_type == SubjectConstant::HONORS) {
                                    $totalBonusCpa += $subject->extra_point_honor;
                                } elseif ($subject->subject_type == SubjectConstant::ADVANCEDPLACEMENT) {
                                    $totalBonusCpa += $subject->extra_point_advanced;
                                } else {
                                    $totalBonusCpa += $subject->bonus_point = 0;
                                }
                            }
                        }
                    }
                }

            $countGpaUnweighted = $countGpaUnweighted == 0 ? 1 : $countGpaUnweighted;
            $cpa                = round($totalCpa / ($totalWeight == 0 ? 1 : $totalWeight), 2);
            $cpaBonusPoint      = ($cpa + $totalBonusCpa);
            $gpaUnweighted      = round($totalGpaUnweighted / $countGpaUnweighted, 2);
            if ($cpaBonusPoint >= self::MAX_CPA_BONUS_POINTS) {
                $cpaBonusPoint = self::MAX_CPA_BONUS_POINTS;
            }

            $isStudying = false;
            if (in_array($gpa['user_id'], $userIdsStudyStatus)) {
                $isStudying = true;
            }

            $scoresCpa[$termId] = [
                'created_by'           => $gpa['created_by'] ?? null,
                'uuid'                 => Uuid::uuid(),
                'user_id'              => $gpa['user_id'] ?? null,
                'term_id'              => $gpa['term_id'] ?? null,
                'earned_credit'        => $gpa['earned_credit'] ?? null,
                'learned_credit'       => $gpa['learned_credit'] ?? null,
                'bonus_gpa'            => $gpa['bonus_gpa'] ?? null,
                'gpa'                  => $gpa['gpa'] ?? null,
                'gpa_wBonus_point'     => $gpa['gpa_wBonus_point'] ?? null,
                'rank'                 => null,
                'school_id'            => $gpa['school_id'] ?? null,
                'grade_id'             => $gpa['grade_id'] ?? null,
                'program_id'           => $gpa['program_id'] ?? null,
                'cpa'                  => $cpa,
                'bonus_cpa'            => $totalBonusCpa,
                'total_learned_credit' => $totalLearnedCredit,
                'total_earned_credit'  => $totalEarnedCredit,
                'cpa_wBonus_point'     => $cpaBonusPoint,
                'is_studying'          => $isStudying,
                'gpa_unweighted'       => $gpaUnweighted,
            ];

        }

        return $scoresCpa;
    }

    /**
     * @param array|null $scoresCpa
     * @param bool       $isRank
     *
     * @throws \Throwable
     */

    private function _createDataCpa(?array $scoresCpa, bool $isRank = false)
    {
        DB::beginTransaction();
        try {

            $data                   = [];
            $data['uuid']           = $scoresCpa['uuid'] ?? Uuid::uuid();
            $data['user_id']        = $scoresCpa['user_id'] ?? null;
            $data['earned_credit']  = $scoresCpa['earned_credit'] ?? null;
            $data['learned_credit'] = $scoresCpa['learned_credit'] ?? null;
            $data['school_id']      = $scoresCpa['school_id'] ?? null;
            $data['program_id']     = $scoresCpa['program_id'] ?? null;
            $data['cpa']            = $scoresCpa['cpa'] ?? null;
            $data['bonus_cpa']      = $scoresCpa['bonus_cpa'] ?? null;
            $data['grade_id']       = $scoresCpa['grade_id'] ?? null;
            if ($isRank) {
                $data['rank'] = $scoresCpa['rank'] ?? null;
            }


            if ($scoresCpa['is_studying']) {
                CpaSQL::updateOrCreate([
                                           'program_id' => $data['program_id'],
                                           'user_id'    => $data['user_id']
                                       ], $data);
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @param object $request
     *
     * @return array
     *
     * @throws Exception
     */
    #[ArrayShape(['message' => "string"])]
    public function calculateCpaScore(object $request): array
    {
        try {

            $payload = [
                'grade_id'   => $request->grade_id ?? null,
                'program_id' => $request->program_id ?? null,
                'school_id'  => $request->school_id ?? null
            ];

            $this->pushToExchange($payload, 'CALCULATE_CPA_SCORE', AMQPExchangeType::DIRECT, 'cpa');

            return ['message' => "Send successfully!"];

        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }

    }


    /**
     * @throws \Throwable
     */
    public function calculateCpaRank(?object $data = null): void
    {
        $gradeId   = $data->grade_id ?? null;
        $programId = $data->program_id ?? null;
        $schoolId  = $data->school_id ?? null;
        $studentId = $data->student_id ?? null;

        $cpa = (new CpaService())->getCurrentCpa($studentId, $programId, $schoolId)
                                 ->when($gradeId, function (Builder|GpaSQL $query) use ($gradeId) {
                                     return $query->where('cpa.grade_id', $gradeId);
                                 })
                                 ->get();

        $grouped = $cpa->groupBy(['program_id', 'grade_id']);
        $cpaRank = [];
        foreach ($grouped as $programIds => $programs) {
            if (empty($programIds)) continue;

            $studentsRank = [];
            $ranks        = [];

            foreach ($programs as $gradeId => $scores) {
                $studentsRank[$gradeId] = $cpwBonusPoint = $this->_calculateCpaWBonusPoint($scores);
                foreach ($cpwBonusPoint as $score) {
                    if (empty($score['grade_id'])) continue;
                    $gpa_wBonus_point                   = empty($score['cpa_wBonus_point']) ? 0.0 : (float)$score['cpa_wBonus_point'];
                    $ranks[$gradeId][$gpa_wBonus_point] = $gpa_wBonus_point;
                }

                // Calculate rank CPA
                foreach ($ranks as $grade_id => $grouped_wBonus_point) {
                    krsort($grouped_wBonus_point);
                    $rank = 1;
                    foreach ($grouped_wBonus_point as $index => $point) {
                        $ranks[$grade_id][$point] = $rank++;
                    }
                }
            }

            foreach ($studentsRank as $gradeId => $users) {
                foreach ($users as $user) {
                    $cpa_wBonus_point = empty($user['cpa_wBonus_point']) ? 0.0 : (float)$user['cpa_wBonus_point'];
                    if (!isset($ranks[$gradeId][$cpa_wBonus_point])) continue;
                    $rankUser  = $ranks[$gradeId][$cpa_wBonus_point];
                    $cpaRank[] = [
                        'uuid'           => $user['uuid'] ?? null,
                        'user_id'        => $user['user_id'] ?? null,
                        'earned_credit'  => $user['earned_credit'] ?? null,
                        'learned_credit' => $user['learned_credit'] ?? null,
                        'school_id'      => $user['school_id'] ?? null,
                        'program_id'     => $user['program_id'] ?? null,
                        'cpa'            => $user['cpa'] ?? null,
                        'bonus_cpa'      => $user['bonus_cpa'] ?? null,
                        'grade_id'       => $user['grade_id'] ?? null,
                        'rank'           => $rankUser,
                        'is_studying'    => true
                    ];
                }
            }
        }

        // dd($cpaRank);
        foreach ($cpaRank as $cpa) {
            $this->_createDataCpa($cpa, true);
        }
        Log::info("Calculate rank CPA", $cpaRank);
    }

    private function _calculateCpaWBonusPoint(Collection $scores): Collection
    {
        if ($scores->isEmpty()) {
            Log::info("Scores CPA is null");

            return collect([]);
        }

        foreach ($scores as $score) {
            $cpaBonusPoint = ($score->cpa + $score->bounus_cpa);
            if ($cpaBonusPoint >= self::MAX_CPA_BONUS_POINTS) {
                $cpaBonusPoint = self::MAX_CPA_BONUS_POINTS;
            }
            $score->cpa_wBonus_point = $cpaBonusPoint;
        }

        return $scores;
    }

}
