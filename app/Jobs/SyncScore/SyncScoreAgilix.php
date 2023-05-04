<?php


namespace App\Jobs\SyncScore;


use App\Models\AgilixEnrollment;
use App\Services\ClassActivityLmsService;
use App\Services\ScoreService;
use Exception;
use Faker\Provider\Uuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Jenssegers\Mongodb\Relations\BelongsTo as MBelongsTo;
use MongoDB\BSON\Decimal128;
use YaangVu\Constant\CodeConstant;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\SisModel\App\Models\impl\ScoreSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;

class SyncScoreAgilix extends SyncScore
{
    protected string    $table   = 'lms_agilix_enrollments';
    protected string    $lmsName = LmsSystemConstant::AGILIX;
    protected UserNoSQL $userNoSQL;

    public function handle()
    {
        parent::handle();

        Log::info($this->instance . " ----- Started sync LMS $this->lmsName scores for school: $this->school");
        $lmsData = $this->getData();
        // Log::info("LMS $this->lmsName  scores to sync: ", $lmsData->toArray());
        foreach ($lmsData as $data)
            $this->sync($data);
        Log::info($this->instance . " ----- Ended sync LMS $this->lmsName scores for school: $this->school");
    }

    public function getData(): Collection
    {
        return AgilixEnrollment::with(['userNoSql.userSql', 'classSql' => function (BelongsTo|MBelongsTo $query) {
            return $query->where('lms_id', '=', $this->lms->id);
        }])
                               ->limit($this->limit)
                               ->orderBy($this->jobName . '_at')
                               ->get()->toBase();
    }

    public function sync($data): void
    {
        Log::info("$this->instance LMS $this->lmsName Score to sync: lms_agilix_enrollments id: $data->_id");

        // If you have no new data to continue
        if ($data->pulledat?->toDateTime() < $data->{$this->jobName . '_at'}?->toDateTime()) {
            $this->callback($data);

            return;
        }

        if (!$data->userNoSql || !$data->userNoSql->userSql) {
            $this->callback($data, false);

            return;
        }

        // 4 withdraw , 7 completed , 10 inactive , 1 active
        if ($data->status != 1){
            $this->callback($data, false);

            return;
        }
        $this->userNoSQL = $data->userNoSql;
        Log::info("$this->instance LMS $this->lmsName Score to sync: 
        [lms_agilix_enrollments_id]: $data->id
        [user_id]: {$data->userNoSql->userSql->id}
        [user_uuid]: {$data->userNoSql->uuid}");

        $classExId = $data->courseid;
        $grade     = $data->grades['achieved'];
        $maxScore  = $data->grades['possible'];
        if ($grade instanceof Decimal128)
            $rawScore = (double)$grade->__toString();
        else
            $rawScore = (double)$grade;

        if ($maxScore instanceof Decimal128)
            $maxScore = (double)$maxScore->__toString();
        else
            $maxScore = (double)$maxScore;

        $percentageScore = $maxScore == 0 ? 0 : (100 * $rawScore / $maxScore);

        $calculatedScore = (new ScoreService())
            ->calculateBySchoolIdAndLmsIdAndClassExIdAndRowScore($this->school->id,
                                                                 $this->lms->id,
                                                                 $classExId,
                                                                 $percentageScore);

        if (!$calculatedScore['class_id']) {
            // Update synced_at to mongodb
            $this->callback($data, false);

            return;
        }
        $score                     = [
            'class_id'        => $calculatedScore['class_id'],
            'user_id'         => $this->userNoSQL->userSql->id,
            'score'           => $percentageScore,
            'current_score'   => $percentageScore,
            'lms_id'          => $this->lms->id,
            'school_id'       => $this->school->id,
            'grade_letter_id' => $calculatedScore['grade_letter_id'],
            'grade_letter'    => $calculatedScore['grade_letter'],
            'is_pass'         => $calculatedScore['is_pass'],
            'real_weight'     => $calculatedScore['real_weight']
        ];
        $score[CodeConstant::UUID] = ScoreSQL::where('class_id', $score['class_id'])
                                             ->where('user_id', $score['user_id'])
                                             ->first()->uuid ?? Uuid::uuid();
        Log::info($this->instance . ' ----- Sync to Sql score: ', $score);
        try {
            $this->_syncScore($score);
            (new ClassActivityLmsService())->calculateActivityScoreViaClassId($calculatedScore['class_id']);

            // Update synced_at to mongodb
            $this->callback($data);
        } catch (Exception $exception) {
            Log::error($this->instance . ' ----- Sync score with data: fail: ' . $exception->getMessage());
            Log::debug($exception);

            // Update synced_at to mongodb
            $this->callback($data, false);
        }
    }

    public function _syncScore($score)
    {
        Log::info("$this->instance LMS $this->lmsName Score to sync in SQL: ", (array)$score);

        return ScoreSQL::updateOrCreate(
            [
                'class_id' => $score['class_id'],
                'user_id'  => $score['user_id'],
            ],
            $score
        );
    }
}
