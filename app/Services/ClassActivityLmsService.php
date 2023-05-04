<?php
/**
 * @Author kyhoang
 * @Date   May 26, 2022
 */

namespace App\Services;

use DB;
use Exception;
use Faker\Provider\Uuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use JetBrains\PhpStorm\ArrayShape;
use Throwable;
use YaangVu\Constant\ClassAssignmentConstant;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\Constant\StatusConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\SystemException;
use YaangVu\SisModel\App\Models\impl\ActivityClassLmsSQL;
use YaangVu\SisModel\App\Models\impl\CalendarSQL;
use YaangVu\SisModel\App\Models\impl\ClassActivityCategorySQL;
use YaangVu\SisModel\App\Models\impl\ClassActivityNoSql;
use YaangVu\SisModel\App\Models\impl\ClassAssignmentSQL;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\impl\LmsSQL;
use YaangVu\SisModel\App\Models\impl\ScoreActivityLmsSQL;
use YaangVu\SisModel\App\Models\impl\UserSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;

class ClassActivityLmsService extends ClassActivityService
{
    protected ClassService $classService;

    public function __construct()
    {
        $this->classService = new ClassService();
        parent::__construct();
    }

    public function setUpParameter(object $request, int|string $classId): bool
    {
        $rules = [
            '*.name'      => 'required|distinct',
            '*.weight'    => 'required|numeric|min:0',
            '*.max_point' => 'required|numeric|min:0|max:10000',
        ];

        $this->doValidate($request, $rules);

        if ($request instanceof Request)
            $arrReq = $request->all();
        else
            $arrReq = $request->toArray();

        $names = array_column($arrReq, 'name');
        if (count($request->all()) < 2)
            throw new BadRequestException(
                ['message' => __("activityCategory.must_larger_2_item")], new Exception()
            );

        if (!in_array(ClassActivityCategoryService::DEFAULT_NAME, $names))
            throw new BadRequestException(
                ['message' => __("activityCategory.no_default_value")], new Exception()
            );

        $class = (new ClassService())->get($classId);
        if (!in_array($class->lms->name, [LmsSystemConstant::AGILIX, LmsSystemConstant::EDMENTUM]))
            throw new BadRequestException(
                ['message' => __("class.not_lms_class")], new Exception()
            );

        $arrWeight = array_column($request->all(), 'weight');
        if (array_sum($arrWeight) > 100) {
            throw new BadRequestException(
                ['message' => __("classActivityCategory.weight_max")], new Exception()
            );
        }

        return parent::setUpParameter($request, $classId);
    }

    public function postSetupParameter(object $request, int $classId, array $idsWasDrop)
    {
        $this->calculateActivityScoreViaClassId($classId);
        parent::postSetupParameter($request, $classId, $idsWasDrop);
    }

    #[ArrayShape(['lms_id' => "null|string", 'avg_score' => "array", 'score_lists' => "array|\Illuminate\Database\Eloquent\Collection"])]
    public function getLmsClassViaClassSQL(ClassSQL $class): array
    {
        $request = request()->all();

        $classActivityStudents = parent::getAllViaClassId($class->id, $request);

        $classActivityStudentsWithoutFilter = $this->model->with(['classActivityCategories.activityClassLms'])
                                                          ->where('class_id', $class->id)
                                                          ->get();

        $totalScore           = 0;
        $totalScoreCoursework = 0;

        $syntheticActivities = [];
        $externalIds         = [];
        $groupActivities     = [];
        $avgScore            = [];
        $data                = collect([]);
        $userUuidsAssignable = $this->getUserUuidsAssignable($class->id);
        $count               = count($userUuidsAssignable);
        foreach ($classActivityStudentsWithoutFilter as $student) {
            if (!in_array($student->student_uuid, $userUuidsAssignable))
                continue;
            foreach ($student->activities as $activity) {
                if (!isset($syntheticActivities[$activity->name])) {
                    $syntheticActivities[$activity->name] = $activity->max_point;
                    $externalIds[$activity->name]         = $activity->external_id;
                }
            }
            $totalScore           += $student->current_score;
            $totalScoreCoursework += $student->current_score_coursework;
        }

        $userIds = array_column($classActivityStudents->toArray(), 'user_id');

        $activityClassLmsIds = ActivityClassLmsSQL::whereClassId($class->id)
                                                  ->pluck('id')
                                                  ->toArray();

        $scoreActivityLms      = ScoreActivityLmsSQL::whereClassId($class->id)
                                                    ->whereIn('activity_class_lms_id', $activityClassLmsIds)
                                                    ->get();
        $decorateScoreActivity = [];
        foreach ($userIds as $userId) {
            foreach ($activityClassLmsIds as $activityClassLmsId) {
                $score = $scoreActivityLms->where('activity_class_lms_id', $activityClassLmsId)
                                          ->where('user_id', $userId)
                                          ->first();

                $decorateScoreActivity[$userId][$activityClassLmsId] = [
                    'user_id' => $userId,
                    'score'   => $score ? $score->score : 0,
                ];
            }
        }

        foreach ($classActivityStudents as $key => $classActivityStudent) {
            if (!in_array($classActivityStudent->student_uuid, $userUuidsAssignable))
                continue;

            $arrColumnActivityNames = array_column($classActivityStudent->activities, 'name');
            $result                 = [];
            foreach ($syntheticActivities as $nameActivity => $maxPoint) {
                $keyActivity = array_search($nameActivity, $arrColumnActivityNames);
                $externalId  = (int)$externalIds[$nameActivity];
                if (!is_bool($keyActivity)) {
                    $itemActivity = $classActivityStudent->activities[$keyActivity];

                    $result[]                     = [
                        'external_id'      => $externalId,
                        'score'            => $itemActivity->score,
                        'name'             => $itemActivity->name,
                        'max_point'        => $itemActivity->max_point,
                        'percentage_score' => $itemActivity->percentage_score ?? $itemActivity->score,
                    ];
                    $groupActivities[$externalId] = [
                        'total' => ($groupActivities[$externalId]['total'] ?? 0) + $itemActivity->score,
                        'count' => ($groupActivities[$externalId]['count'] ?? 0) + 1,
                    ];
                } else {
                    $result[]                     = [
                        'external_id'      => $externalId,
                        'score'            => 0,
                        'name'             => $nameActivity,
                        'max_point'        => $maxPoint,
                        'percentage_score' => 0
                    ];
                    $groupActivities[$externalId] = [
                        'total' => ($groupActivities[$externalId]['total'] ?? 0),
                        'count' => ($groupActivities[$externalId]['count'] ?? 0) + 1,
                    ];
                }
            }
            $classActivityStudent->activities = $result ?? [];
            $categories                       = [];
            foreach ($classActivityStudent->classActivityCategories as $classActivityCategory) {
                $activities           = [];
                $totalPercentageScore = 0;
                foreach ($classActivityCategory->activityClassLms as $activity) {
                    $activityLmsScore               = $decorateScoreActivity[$classActivityStudent->user_id][$activity['id']];
                    $activities[]                   = [
                        'id'                         => $activity->id,
                        'name'                       => $activity->name,
                        'max_point'                  => $activity->max_point,
                        'class_activity_category_id' => $activity->class_activity_category_id,
                        'score'                      => $activityLmsScore
                    ];
                    $groupActivities[$activity->id] = [
                        'total' => ($groupActivities[$activity->id]['total'] ?? 0) + $activityLmsScore['score'],
                        'count' => ($groupActivities[$activity->id]['count'] ?? 0) + 1,
                    ];
                    $totalPercentageScore           += $activityLmsScore['score'] / $activity->max_point;
                }
                $countActivities = count($activities);
                $categories[]    = [
                    'id'                 => $classActivityCategory->id,
                    'name'               => $classActivityCategory->name,
                    'weight'             => $classActivityCategory->weight,
                    'activity_class_lms' => $activities ?? [],
                    'avg_category'       => $countActivities != 0 ? ($totalPercentageScore / $countActivities) * 100 : 0
                ];
            }
            unset($classActivityStudent->classActivityCategories);
            $classActivityStudent->class_activity_categories = $categories ?? [];

            $data->add($classActivityStudent);
        }
        foreach ($groupActivities as $idActivity => $groupActivity) {
            $avgScore[$idActivity] = $groupActivity['total'] / $groupActivity['count'];
        }
        $avgStudentScore                      = $count === 0 ? 0 : $totalScore / $count;
        $avgStudentScoreCoursework            = $count === 0 ? 0 : $totalScoreCoursework / $count;
        $avgStudentScoreLetter                = (new ScoreService())
            ->calculateByClassIdAndRawScore($class->id, $avgStudentScore);
        $avgStudentScoreLetterCoursework      = (new ScoreService())
            ->calculateByClassIdAndRawScore($class->id, $avgStudentScoreCoursework);
        $avgScore['current_score']            = $avgStudentScore;
        $avgScore['final_score']              = $class->status == StatusConstant::CONCLUDED ? $avgStudentScore : 0;
        $avgScore['grade_letter']             = $avgStudentScoreLetter['grade_letter'] ?? null;
        $avgScore['current_score_coursework'] = $avgStudentScoreCoursework;
        $avgScore['final_score_coursework']
                                              = $class->status == StatusConstant::CONCLUDED ? $avgStudentScoreCoursework : 0;
        $avgScore['grade_letter_coursework']  = $avgStudentScoreLetterCoursework['grade_letter'] ?? null;

        return [
            'lms_id'      => $class->lms_id,
            'avg_score'   => $avgScore,
            'score_lists' => $data
        ];
    }

    /**
     * @Description
     *
     * @Author kyhoang
     * @Date   Jun 06, 2022
     *
     * @param object     $request
     * @param int|string $classId
     *
     * @return array
     * @throws Throwable
     */
    public function updateActivityScore(object $request, int|string $classId): array
    {
        if ($request instanceof Request)
            $arrReq = $request->all();
        else
            $arrReq = $request->toArray();
        $class = $this->classService->get($classId);
        if (!in_array($class->lms->name, [LmsSystemConstant::AGILIX, LmsSystemConstant::EDMENTUM]))
            throw new BadRequestException(
                ['message' => __("class.not_lms_class")], new Exception()
            );
        $arrActivityClass = ActivityClassLmsSQL::whereClassId($class->id)->pluck('max_point', 'id');
        $rules            = [
            '*.user_id'     => 'required|exists:users,id',
            '*.activity_id' => 'required|exists:activity_class_lms,id'
        ];

        $this->doValidate($request, $rules);

        foreach ($arrReq as $key => $req) {
            if (!($req['activity_id'] ?? null))
                continue;
            $rules["$key.score"] = "required|numeric|min:0|max:" . $arrActivityClass[$req['activity_id']];
        }

        $this->doValidate($request, $rules);

        try {
            DB::beginTransaction();
            foreach ($arrReq as $req) {
                $req['lms_id']   = $class->lms->id;
                $req['class_id'] = $class->id;
                (new ScoreActivityLmsService())->upsert($req, $class->id);
                (new ScoreService())->upsert($req);
            }

            $this->calculateActivityScoreViaClassId($class->id);


            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }


        return $request->all();
    }

    /**
     * @Description calculate all class Activity via class SQL
     * @Author      Edogawa Conan
     * @Date        Jun 01, 2022
     *
     * @param int $classId
     *
     * @return bool
     */
    public function calculateActivityScoreViaClassId(int $classId): bool
    {
        $class = $this->classService->get($classId);
        if ($class->lms->name == LmsSystemConstant::SIS)
            return false;

        $classActivities = ClassActivityNoSql::whereClassId($class->id)->get();
        $defaultCategory = (new ClassActivityCategoryService())->getDefaultCategoryViaClassId($class->id);
        foreach ($classActivities as $classActivity) {

            if (!$defaultCategory) {
                $classActivity->{'final_score_coursework'}   = $classActivity->final_score;
                $classActivity->{'current_score_coursework'} = $classActivity->current_score;
                $classActivity->grade_letter_coursework      = 'D';
                $classActivity->save();

                return true;
            }

            $scoreLms
                = ((float)$classActivity->final_score / (float)$defaultCategory->max_point) * $defaultCategory->weight;

            $scoreActivityCoursework = DB::query()
                                         ->fromSub(
                                             ClassActivityCategorySQL::query()
                                                                     ->selectRaw('
                                                                            CASE WHEN (AVG(divide_score.score) * class_activity_categories.weight) IS NOT NULL
                                                                            THEN
                                                                                (AVG(divide_score.score) * class_activity_categories.weight)
                                                                            ELSE
                                                                                0
                                                                            END as avg_score ,
                                                                            class_activity_categories.id ,class_activity_categories.weight')
                                                                     ->joinSub($this->_queryGetAvgScoreActivity($class->id,
                                                                                                                $classActivity->user_id),
                                                                               'divide_score',
                                                                               'divide_score.category_id', '=',
                                                                               'class_activity_categories.id')
                                                                     ->where('class_activity_categories.class_id',
                                                                             $class->id)
                                                                     ->where('class_activity_categories.is_default',
                                                                             false)
                                                                     ->groupBy('class_activity_categories.id'),
                                             'total_avg_score')
                                         ->sum('total_avg_score.avg_score');

            $classActivity->current_score_coursework
                             = $classActivity->final_score_coursework = round($scoreLms + $scoreActivityCoursework, 2);
            $calculateScore = (new ScoreService())
                ->calculateByClassIdAndRawScore($class->id, $classActivity->current_score_coursework);

            $classActivity->grade_letter_coursework    = $calculateScore['grade_letter'];
            $classActivity->grade_letter_id_coursework = $calculateScore['grade_letter_id'];
            $classActivity->save();

            $score = ScoreService::getViaUserIdAndClassId($classActivity->user_id, $class->id);
            if ($score) {
                $score->grade_letter_id = $calculateScore['grade_letter_id'];
                $score->grade_letter    = $calculateScore['grade_letter'];
                $score->is_pass         = $calculateScore['is_pass'];
                $score->real_weight     = $calculateScore['real_weight'];
                $score->current_score   = $classActivity->current_score_coursework;
                $score->save();
            }
        }

        return true;
    }

    private function _queryGetAvgScoreActivity(int $classId, int $userId): CalendarSQL|Builder
    {
        return ScoreActivityLmsSQL::query()
                                  ->selectRaw('score_activity_lms.score / activity_class_lms.max_point as score , activity_class_lms.class_activity_category_id as category_id')
                                  ->join('activity_class_lms', 'activity_class_lms.id', '=',
                                         'score_activity_lms.activity_class_lms_id')
                                  ->where('score_activity_lms.class_id', $classId)
                                  ->where('score_activity_lms.user_id', $userId);
    }

    /**
     * @Description
     *
     * @Author kyhoang
     * @Date   Jun 02, 2022
     *
     * @param int $classId
     *
     * @return bool
     * @throws Throwable
     */
    public function removeAllParameters(int $classId): bool
    {
        $class = $this->classService->get($classId);
        try {
            DB::beginTransaction();
            (new ClassActivityCategoryService())->deleteByClassId($class->id);
            (new ActivityClassLmsService())->deleteByClassId($class->id);
            (new ScoreActivityLmsService())->deleteByClassId($class->id);
            DB::commit();

            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @Author Edogawa Conan
     * @Date   Jun 04, 2022
     *
     * @param int $userId
     * @param int $classId
     *
     * @return ClassActivityNoSql
     */
    public function addFakeDataViaUserIdAndCLassId(int $userId, int $classId): ClassActivityNoSql
    {
        $userSql = UserSQL::whereId($userId)->with('userNoSql')->first();
        $class   = (new ClassService())->get($classId);
        $school  = SchoolServiceProvider::$currentSchool;

        $classActivityNoSql                = new ClassActivityNoSql();
        $classActivityNoSql->class_id      = $class->id;
        $classActivityNoSql->uuid          = Uuid::uuid();
        $classActivityNoSql->student_code  = $userSql->userNoSql->student_code;
        $classActivityNoSql->student_uuid  = $userSql->userNoSql->uuid;
        $classActivityNoSql->school_uuid   = $school->uuid;
        $classActivityNoSql->lms_id        = $class->lms_id;
        $classActivityNoSql->lms_name      = $class->lms->name ?? null;
        $classActivityNoSql->user_nosql_id = $userSql->userNoSql->id;
        if ($classActivityNoSql->lms_name == LmsSystemConstant::EDMENTUM)
            $classActivityNoSql->edmentum_id = $class->external_id;
        else
            $classActivityNoSql->agilix_id = $class->external_id;
        $classActivityNoSql->user_id       = $userSql->id;
        $classActivityNoSql->activities    = [];
        $classActivityNoSql->source        = $classActivityNoSql->lms_name;
        $classActivityNoSql->grade_letter  = null;
        $classActivityNoSql->is_pass       = null;
        $classActivityNoSql->final_score   = 0;
        $classActivityNoSql->current_score = 0;
        $classActivityNoSql->save();

        return $classActivityNoSql;
    }

    public function syncStudentAssignmentToClassActivity(): bool
    {
        $lmsIds           = LmsSQL::query()->whereIn('name', [LmsSystemConstant::EDMENTUM, LmsSystemConstant::AGILIX])
                                  ->pluck('id')->toArray();
        $classIds         = ClassSQL::query()->whereIn('lms_id', $lmsIds)->pluck('id')->toArray();
        $classAssignments = ClassAssignmentSQL::whereAssignment(ClassAssignmentConstant::STUDENT)
                                              ->whereIn('class_id', $classIds)->get();
        foreach ($classAssignments as $classAssignment) {
            if (!$this->getViaUserIdAndClassId($classAssignment->user_id, $classAssignment->class_id))
                $this->addFakeDataViaUserIdAndCLassId($classAssignment->user_id, $classAssignment->class_id);
        }

        return true;
    }

    public function getScoreActivityLmsByClassId(int   $classId,
                                                 array $userIds): \Illuminate\Database\Eloquent\Collection|array
    {
        $activityClassLms = ActivityClassLmsSQL::whereClassId($classId)
                                               ->get()
                                               ->toArray();

        $scoreActivityLms = ScoreActivityLmsSQL::query()
                                               ->join('activity_class_lms', 'activity_class_lms.id',
                                                      'score_activity_lms.activity_class_lms_id')
                                               ->join('class_activity_categories', 'class_activity_categories.id',
                                                      'activity_class_lms.class_activity_category_id')
                                               ->join('users', 'users.id', 'score_activity_lms.user_id')
                                               ->select([
                                                            'score_activity_lms.score',
                                                            'activity_class_lms.name',
                                                            'activity_class_lms.max_point',
                                                            'activity_class_lms.max_point',
                                                            'class_activity_categories.name as category_name',
                                                            'activity_class_lms.class_activity_category_id as external_id',
                                                            'activity_class_lms.id as activity_class_lms_id',
                                                            'users.id as user_id'
                                                        ])
                                               ->where('score_activity_lms.class_id', $classId)
                                               ->whereIn('activity_class_lms_id', array_column($activityClassLms, 'id'))
                                               ->get();
        $scoreActivity    = [];
        foreach ($userIds as $userId) {
            foreach ($activityClassLms as $activity) {
                $score = $scoreActivityLms->where('activity_class_lms_id', $activity['id'])
                                          ->where('user_id', $userId)
                                          ->first();

                $scoreActivity[] = [
                    'id_activity'   => $activity['id'],
                    'score'         => $score ? $score->score : 0,
                    'name'          => $activity['name'],
                    'max_point'     => $score ? $score->max_point : 0,
                    'category_name' => $score ? $score->category_name : "",
                    'external_id'   => $score ? $score->external_id : 0,
                    'user_id'       => $userId,
                ];
            }
        }

        return $scoreActivity;
    }
}
