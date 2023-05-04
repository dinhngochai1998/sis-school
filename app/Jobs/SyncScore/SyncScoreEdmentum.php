<?php

namespace App\Jobs\SyncScore;

use App\Services\ClassActivityLmsService;
use App\Services\ScoreService;
use Exception;
use Faker\Provider\Uuid;
use Illuminate\Support\Facades\Log;
use YaangVu\Constant\CodeConstant;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\SisModel\App\Models\impl\ScoreSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Models\impl\UserSQL;
use YaangVu\SisModel\App\Models\MongoModel;

class SyncScoreEdmentum extends SyncScore
{
    protected string $table   = 'lms_edmentum_grades';
    protected string $lmsName = LmsSystemConstant::EDMENTUM;

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        parent::handle();

        Log::info($this->instance . " ----- Started sync Scores for LMS '$this->lmsName' and school: '{$this->school->name}'");
        $scores = $this->getData();
        // Log::info($this->instance . " ----- Scores to sync: ", $scores->toArray());
        foreach ($scores as $score)
            $this->sync($score);
        Log::info($this->instance . " ----- Ended sync Scores for LMS '$this->lmsName' and school: '{$this->school->name}'");
    }

    public function getData(): mixed
    {
        return MongoModel::from($this->table)
                         ->limit($this->limit)
                         ->orderBy($this->jobName . '_at', 'asc')
                         ->get()
                         ->toBase();
    }

    public function sync($data): void
    {
        // Log::info("$this->instance LMS $this->lmsName Score to sync: ", (array)$data);
        Log::info("$this->instance LMS $this->lmsName Score to sync: [lms_edmentum_grades]: $data->id");

        // If you have no new data to continue
        if ($data->pulledat?->toDateTime() < $data->{$this->jobName . '_at'}?->toDateTime()) {
            $this->callback($data);

            return;
        }

        if ($data->CourseGrade === null) {
            Log::info($this->instance . " ----- Score (CourseGrade) is null", $data->toArray());
            $this->callback($data, false);

            return;
        }

        $classExId       = $data->ClassId;
        $rawScore        = $data->CourseGrade;
        $calculatedScore = (new ScoreService())
            ->calculateBySchoolIdAndLmsIdAndClassExIdAndRowScore($this->school->id,
                                                                 $this->lms->id,
                                                                 $classExId,
                                                                 $rawScore);

        $userNoSQL = UserNoSQL::where('external_id.' . $this->lmsName, '=', (string)$data->LearnerUserId)->first();
        if ($userNoSQL === null) {
            Log::info($this->instance . " ----- User is null. UserExternalId: $data->LearnerUserId");
            $this->callback($data, false);

            return;
        }

        $user = UserSQL::whereUuid($userNoSQL->uuid)->first();
        if ($user === null) {
            Log::info($this->instance . " ----- User was not found with uuid: $userNoSQL->uuid");
            $this->callback($data, false);

            return;
        }

        $score                     = [
            'class_id'        => $calculatedScore['class_id'],
            'user_id'         => $user->id,
            'score'           => $data->CourseGrade,
            'current_score'   => $data->CurrentGrade,
            'lms_id'          => $this->lms->id,
            'school_id'       => $this->school->id,
            'grade_letter_id' => $calculatedScore['grade_letter_id'],
            'grade_letter'    => $calculatedScore['grade_letter'],
            'is_pass'         => $calculatedScore['is_pass'],
            'real_weight'     => $calculatedScore['real_weight']
        ];
        $score[CodeConstant::UUID] = ScoreSQL::where('class_id', $score['class_id'])
                                             ->where('user_id', $score['user_id'])->first()->uuid ?? Uuid::uuid();

        Log::info($this->instance . " ----- Score to sync: ", $score);
        try {
            ScoreSQL::updateOrCreate(
                [
                    'class_id' => $score['class_id'],
                    'user_id'  => $score['user_id']
                ],
                $score
            );
            (new ClassActivityLmsService())->calculateActivityScoreViaClassId($score['class_id']);


            // Update synced_at to mongodb
            $this->callback($data);
        } catch (Exception $exception) {
            Log::debug($exception);
            $this->callback($data, false);
        }
    }
}
