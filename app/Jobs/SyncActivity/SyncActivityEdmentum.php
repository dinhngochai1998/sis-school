<?php

namespace App\Jobs\SyncActivity;

use App\Jobs\SyncDataJob;
use App\Models\EdementumActivities;
use App\Models\EdementumActivityScores;
use App\Models\EdmentumClass;
use App\Services\ClassActivityLmsService;
use App\Services\ClassActivityService;
use App\Services\ScoreService;
use Exception;
use Faker\Provider\Uuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Jenssegers\Mongodb\Relations\BelongsTo as MBelongsTo;
use JetBrains\PhpStorm\Pure;
use stdClass;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\SisModel\App\Models\impl\ClassActivityNoSql;


class SyncActivityEdmentum extends SyncActivity
{

    protected string $lmsName = LmsSystemConstant::EDMENTUM;
    protected string $table   = 'lms_edmentum_classes';
    public int       $limit   = 100;
    public array     $users;

    public function handle()
    {
        SyncDataJob::handle();

        Log::info("$this->instance  ----- Started sync LMS $this->lmsName Activities for school: $this->school");
        $classes = $this->getData();
        // Log::info("LMS $this->lmsName Activities to sync: ", $scores->toArray());
        foreach ($classes as $class)
            $this->sync($this->_handleActivityScoreData($class));
        Log::info("$this->instance ----- Ended sync LMS $this->lmsName Activities for school: $this->school");
    }

    /**
     * @Author yaangvu
     * @Date   Aug 23, 2021
     *
     * @return Collection
     */
    public function getData(): Collection
    {
        return EdmentumClass::query()
                            ->with(['classSql' => function (BelongsTo|MBelongsTo $query) {
                                return $query->where('lms_id', '=', $this->lms->id);
                            }])
                            ->orderBy($this->jobName . '_at')
            // ->where('ClassId', 645374)
                            ->limit($this->limit)
                            ->get()
                            ->toBase();

    }

    #[Pure]
    public function _handleActivityScore(object $activityScore): object
    {
        $data              = new stdClass();
        $data->source      = $this->lmsName;
        $data->edmentum_id = $activityScore->_id;
        $data->activities  = $activityScore->activities;

        return $data;
    }

    /**
     * @param object $class
     *
     * @return object
     */
    public function _handleActivityScoreData(object $class): object
    {
        $scores                = $this->_getScoreActivityViaClassExId($class->ClassId);
        $activities            = [];
        $this->classSQL        = $class->classSql ?? null;
        $edmentumActivity      = EdementumActivities::query()
                                                    ->where('ClassId', $class->ClassId)
                                                    ->get();
        $activityCategoryNames = $edmentumActivity->pluck('GradetrackerCategoryName', 'ResourceNodeId');
        $activityTitles        = $edmentumActivity->pluck('ActivityTitle', 'ResourceNodeId');
        foreach ($scores as $score) {
            $resourceNodeId = $score->ResourceNodeId;

            $maxPoint      = $score->activity->GradetrackerPossibleScore;
            $activityScore = $score->Score;
            $userNoSql     = $score->userNoSql;
            $userUuid      = $userNoSql->uuid ?? null;
            if (!$userUuid)
                continue;

            $activities[$userUuid][] = [
                'score'            => $activityScore,
                'name'             => $score->activity->ActivityTitle,
                'max_point'        => $maxPoint,
                'percentage_score' => $maxPoint == 0
                    ? 0
                    : (100 * $activityScore / $maxPoint),
                'resource_node_id' => $resourceNodeId,
                'category_name'    => $activityCategoryNames[$resourceNodeId] ?? null,
                'external_id'      => $resourceNodeId,
            ];

            $this->users[$userUuid] = [
                'student_code'  => $userNoSql?->student_code,
                'student_uuid'  => $userUuid,
                'user_nosql_id' => $userNoSql?->_id,
                'user_id'       => $userNoSql?->userSql?->id,
            ];
        }

        // add activity with none score
        foreach ($activities as $keyActivity =>  $activity) {
            $activityNames = array_column($activity, 'name');
            foreach ($activityTitles as $resourceNodeTitle => $activityTitle) {
                if (in_array($activityTitle, $activityNames))
                    continue;

                $activities[$keyActivity][] = [
                    'score'            => 0,
                    'name'             => $activityTitle,
                    'max_point'        => 0,
                    'percentage_score' => 0,
                    'resource_node_id' => $resourceNodeTitle,
                    'category_name'    => $activityCategoryNames[$resourceNodeTitle] ?? null,
                    'external_id'      => $resourceNodeTitle,
                ];
            }
        }

        $class->userActivities = $activities;

        return $class;
    }

    /**
     * get all Score Activity via class external id
     * @Author Edogawa Conan
     * @Date   May 04, 2022
     *
     * @param int $classExId
     *
     * @return Collection
     */
    private function _getScoreActivityViaClassExId(int $classExId): Collection
    {
        return EdementumActivityScores::with(['activity', 'userNoSql.userSql', 'classSql' => function (BelongsTo|MBelongsTo $query) {
            return $query->where('lms_id', '=', $this->lms->id);
        }])
                                      ->where('ClassId', $classExId)
                                      ->get()
                                      ->toBase();
    }

    public function sync($data): void
    {
        $userActivities = $data?->userActivities;

        // If you have no new data to continue
        if ($data->pulledat?->toDateTime() < $data->{$this->jobName . '_at'}?->toDateTime()) {
            $this->callback($data);

            return;
        }

        if (!$data->classSql || !$data->userActivities) {
            Log::error("$this->instance ----- Sync Activities: fail: Data is null");
            $this->callback($data, false);

            return;
        }

        Log::info("LMS $this->lmsName Activity to sync:
        [id]: $data->id");

        $class = $data;

        try {
            foreach ($userActivities as $userUuid => $userActivity) {
                $activity = (new ClassActivityService())->getBySchoolUuidAndClassIdAndStudentUuid($this->school->uuid,
                                                                                                  $this->classSQL?->id,
                                                                                                  $userUuid);

                if ($activity === null) {
                    $activity       = new ClassActivityNoSql();
                    $activity->uuid = Uuid::uuid();
                }
                $user = $this->users[$userUuid] ?? null;


                $activity->student_code         = $user['student_code'] ?? null;
                $activity->student_uuid         = $user['student_uuid'] ?? null;
                $activity->school_uuid          = $this->school->uuid;
                $activity->lms_id               = $this->lms->id;
                $activity->lms_name             = $this->lms->name;
                $activity->class_id             = $this->classSQL?->id;
                $activity->user_nosql_id        = $user['user_nosql_id'] ?? null;
                $activity->edmentum_id          = $data->_id ?? null;
                $activity->edmentum_external_id = $data->edmentum_external_id ?? null;
                $activity->agilix_id            = $data->agilix_id ?? null;
                $activity->agilix_external_id   = $data->agilix_external_id ?? null;
                $activity->user_id              = $user['user_id'] ?? null;
                $activity->activities           = $userActivity;
                $activity->source               = $this->lmsName ?? null;

                $validated = $this->_validate($activity);
                if (is_array($validated)) {
                    Log::error("$this->instance ----- Sync Activities Validate fail: ", $validated);

                    return;
                }

                $scoreSql                = (new ScoreService())->getHighestByUserIdAndClassId($activity->user_id,
                                                                                              $activity->class_id);
                $activity->grade_letter  = $scoreSql->grade_letter ?? null;
                $activity->is_pass       = $scoreSql->is_pass ?? null;
                $activity->final_score   = $scoreSql->score ?? null;
                $activity->current_score = $scoreSql->current_score ?? null;

                $activity->save();

                (new ClassActivityLmsService())->calculateActivityScoreViaClassId($activity->class_id);
            }

            $this->callback($class);
        } catch (Exception $exception) {
            Log::error("$this->instance ----- Sync Activities: fail: " . $exception->getMessage());
            Log::debug($exception);
            $this->callback($class, false);
        }
    }
}
