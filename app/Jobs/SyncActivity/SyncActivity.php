<?php

namespace App\Jobs\SyncActivity;

use App\Jobs\SyncDataJob;
use App\Services\ClassActivityLmsService;
use App\Services\ClassActivityService;
use App\Services\ScoreService;
use Exception;
use Faker\Provider\Uuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\ClassActivityNoSql;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;

abstract class SyncActivity extends SyncDataJob
{
    public ?ClassSQL  $classSQL;
    public ?UserNoSQL $userNoSQL;
    public string     $jobName = 'sync_activity';
    public int        $limit   = 500;

    public function handle()
    {
        parent::handle();

        Log::info("$this->instance  ----- Started sync LMS $this->lmsName Activities for school: $this->school");
        $scores = $this->getData();
        // Log::info("LMS $this->lmsName Activities to sync: ", $scores->toArray());
        foreach ($scores as $score)
            $this->sync($score);
        Log::info("$this->instance ----- Ended sync LMS $this->lmsName Activities for school: $this->school");
    }

    /**
     * @return Collection|array|null
     */
    public abstract function getData(): Collection|array|null;

    /**co
     *
     * @param $data {name, score, max_point}
     *
     * @return void
     */
    public function sync($data): void
    {
        // If you have no new data to continue
        if ($data->pulledat?->toDateTime() < $data->{$this->jobName . '_at'}?->toDateTime()) {
            $this->callback($data);

            return;
        }

        if (!$data->classSql || !$data->userNoSql || !$data->userNoSql->userSql) {
            Log::error("$this->instance ----- Sync Activities: fail: Data is null");
            $this->callback($data, false);

            return;
        }

        Log::info("LMS $this->lmsName Activity to sync:
        [id]: $data->id,
        [user_uuid]: {$data->userNoSql->uuid}
        [userSql_id]: {$data->userNoSql->userSql->id}
        [classSql_id]: {$data->classSql->id}");

        $score = $data;

        $data     = $this->_handleActivityScore($data);
        $activity = (new ClassActivityService())->getBySchoolUuidAndClassIdAndStudentUuid($this->school->uuid,
                                                                                          $this->classSQL?->id,
                                                                                          $this->userNoSQL?->uuid);
        if ($activity === null) {
            $activity       = new ClassActivityNoSql();
            $activity->uuid = Uuid::uuid();
        }

        // Handle activities
        // Comment merge activities
        // $activities = array_merge($activity->activities ?? [], $data->activities);
        // $activities = array_intersect_key($activities, array_unique(array_map('serialize', $activities)));
        $activities                     = $data->activities;
        $activity->school_uuid          = $this->school->uuid;
        $activity->lms_id               = $this->lms->id;
        $activity->lms_name             = $this->lms->name;
        $activity->class_id             = $this->classSQL?->id;
        $activity->student_code         = $this->userNoSQL?->student_code;
        $activity->student_uuid         = $this->userNoSQL?->uuid;
        $activity->user_nosql_id        = $this->userNoSQL?->_id;
        $activity->user_id              = $this->userNoSQL?->userSql?->id;
        $activity->activities           = $activities;
        $activity->source               = $data->source ?? null;
        $activity->edmentum_id          = $data->edmentum_id ?? null;
        $activity->edmentum_external_id = $data->edmentum_external_id ?? null;
        $activity->agilix_id            = $data->agilix_id ?? null;
        $activity->agilix_external_id   = $data->agilix_external_id ?? null;

        try {
            $validated = $this->_validate($activity);
            if (is_array($validated)) {
                Log::error("$this->instance ----- Sync Activities: fail: ", $validated);
                $this->callback($score, false);

                return;
            }

            $scoreSql                = (new ScoreService())->getHighestByUserIdAndClassId($activity->user_id,
                                                                                          $activity->class_id);
            $activity->final_score   = $scoreSql->score ?? null;
            $activity->current_score = $scoreSql->current_score ?? null;
            $activity->grade_letter  = $scoreSql->grade_letter ?? null;
            $activity->is_pass       = $scoreSql->is_pass ?? null;

            $activity->save();

            (new ClassActivityLmsService())->calculateActivityScoreViaClassId($activity->class_id);
            $this->callback($score);
        } catch (Exception $exception) {
            Log::error("$this->instance ----- Sync Activities: fail: " . $exception->getMessage());
            Log::debug($exception);
            $this->callback($score, false);
        }
    }

    /**
     * @Author yaangvu
     * @Date   Aug 23, 2021
     *
     * @param object $activityScore
     *
     * @return object|null
     */
    public function _handleActivityScore(object $activityScore): ?object
    {
        return null;
    }

    /**
     * @Description Validate data before persist to databases
     * @Author      yaangvu
     * @Date        Aug 25, 2021
     *
     * @param Collection|Model|\Jenssegers\Mongodb\Eloquent\Model $data
     *
     * @return bool|array
     */
    public function _validate(Collection|Model|\Jenssegers\Mongodb\Eloquent\Model $data): bool|array
    {
        $rules = [
            'uuid'         => 'required',
            'school_uuid'  => 'required',
            'class_id'     => 'required',
            'student_uuid' => 'required',
            'activities'   => 'required',
            'source'       => 'required',
        ];

        BaseService::$validateThrowAble = false;

        return BaseService::doValidate($data, $rules);
    }
}
