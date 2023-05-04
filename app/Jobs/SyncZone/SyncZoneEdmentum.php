<?php

namespace App\Jobs\SyncZone;

use Exception;
use Illuminate\Support\Facades\Log;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\SisModel\App\Models\MongoModel;

class SyncZoneEdmentum extends SyncZone
{
    protected string $table   = 'lms_edmentum_programs';
    protected string $lmsName = LmsSystemConstant::EDMENTUM;

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        parent::handle();

        Log::info($this->instance . " ----- Started sync Programs for LMS '$this->lmsName' and school: '{$this->school->name}'");
        $programs = $this->getData();
        Log::info($this->instance . " ----- Programs to sync: ", $programs->toArray());
        foreach ($programs as $program)
            $this->sync($program);
        Log::info($this->instance . " ----- Ended sync Programs for LMS '$this->lmsName' and school: '{$this->school->name}'");
    }

    public function getData(): mixed
    {
        return MongoModel::from($this->table)
                         ->where('Application', '<>', 22) //exclude "ProgramTitle": "Exact Path"
                         ->orderBy($this->jobName . '_at')
                         ->limit($this->limit)
                         ->get()
                         ->toBase();
    }

    /**
     * @param $data
     */
    public function sync($data): void
    {
        Log::info($this->instance . " ----- Sync program $data->ProgramId to School: " . $this->schoolNoSql->name);
        // If you have no new data to continue
        if ($data->pulledat?->toDateTime() < $data->{$this->jobName . '_at'}?->toDateTime()) {
            $this->callback($data);

            return;
        }
        try {
            $zones    = (array)$this->schoolNoSql->zones;
            $lmsZones = $zones[$this->lms->uuid] ?? [];
            $newZones = [
                "id"    => $data->ProgramId,
                "title" => $data->ProgramTitle
            ];

            $lmsZones
                = array_merge(
                $lmsZones,
                [$newZones]

            );

            $zones[$this->lms->uuid] = array_intersect_key($lmsZones, array_unique(array_map('serialize', $lmsZones)));

            $this->schoolNoSql->zones = (object)$zones;

            $this->schoolNoSql->save();

            $this->callback($data);
        } catch (Exception $exception) {
            Log::error($this->instance . ' Sync program: ' . $data->ProgramId . ' fail: ' . $exception->getMessage());
            Log::debug($exception);

            // Update synced_at to mongodb
            $this->callback($data, false);
        }
    }
}
