<?php


namespace App\Jobs\SyncClass;


use App\Jobs\SyncDataJob;
use Carbon\Carbon;
use Faker\Provider\Uuid;
use Illuminate\Support\Facades\Log;
use YaangVu\Constant\CodeConstant;
use YaangVu\Constant\StatusConstant;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\MongoModel;

abstract class SyncClass extends SyncDataJob
{
    public int    $limit   = 500;
    public string $jobName = 'sync_class';

    public function handle()
    {
        parent::handle();

        Log::info($this->instance . " ----- Started sync LMS $this->lmsName classes for school: $this->school");
        $lmsData = $this->getData();
        // Log::info("LMS $this->lmsName  Classes to sync: ", $lmsData->toArray());
        foreach ($lmsData as $data)
            $this->sync($data);
        Log::info($this->instance . " ----- Ended sync LMS $this->lmsName classes for school: $this->school");
    }

    /**
     * @param array $class
     *
     * @return ClassSQL|bool
     */
    protected function _syncClass(array $class): ClassSQL|bool
    {
        Log::info($this->instance . " ----- Sync ClassNoSQL", $class);

        $classSql = ClassSQL::whereLmsId($class['lms_id'])
                            ->where(CodeConstant::EX_ID, $class[CodeConstant::EX_ID])
                            ->first();

        if ($classSql !== null) {
            if (!in_array($classSql->status ?? null,
                          [StatusConstant::ACTIVE, StatusConstant::INACTIVE, StatusConstant::ON_GOING]))
                return true;

            if ($class['status'] == StatusConstant::PENDING)
                return true;

            $pulledAt = $class['pulled_at']?->toDateTime();
            // if when client update class -> wait java worker sync data from LMS
            if ($pulledAt && $classSql->updated_at->toDateTime() > $pulledAt)
                return true;
        }

        $class[CodeConstant::UUID] = $classSql->uuid ?? Uuid::uuid();
        $class['start_date']       = Carbon::createFromTimestampMs($class['start_date'])->toDateString();
        $class['end_date']         = Carbon::createFromTimestampMs($class['end_date'])->toDateString();

        Log::info($this->instance . " ----- Sync ClassSQL", $class);

        $classSql = ClassSQL::updateOrCreate(
            [
                'lms_id'            => $class['lms_id'],
                CodeConstant::EX_ID => $class[CodeConstant::EX_ID]
            ],
            $class
        );

        $class['class_id'] = $classSql->id;
        $classNosql        = new MongoModel();
        $classNosql->setTable('classes')
                   ->updateOrCreate(
                       [
                           'lms_id'            => $class['lms_id'],
                           CodeConstant::EX_ID => $class[CodeConstant::EX_ID]
                       ],
                       $class
                   );

        return $classSql;
    }
}
