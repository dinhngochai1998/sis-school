<?php

namespace App\Jobs\SyncZone;

use Exception;
use Illuminate\Support\Facades\Log;
use YaangVu\Constant\LmsSystemConstant;
use YaangVu\SisModel\App\Models\MongoModel;

class SyncZoneAgilix extends SyncZone
{
    protected string $table   = 'lms_agilix_domains';
    protected string $lmsName = LmsSystemConstant::AGILIX;

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        parent::handle();

        Log::info($this->instance . " ----- Started sync Domain for LMS '$this->lmsName' and school: '{$this->school->name}'");
        $domains = $this->getData();
        Log::info($this->instance . " ----- Domain to sync: ", $domains->toArray());
        foreach ($domains as $domain)
            $this->sync($domain);
        Log::info($this->instance . " ----- Ended sync Domain for LMS '$this->lmsName' and school: '{$this->school->name}'");
    }

    public function getData(): mixed
    {
        return MongoModel::from($this->table)
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
        Log::info($this->instance . " ----- Sync domain $data->id to School: " . $this->schoolNoSql->name);

        // If you have no new data to continue
        if ($data->pulledat?->toDateTime() < $data->{$this->jobName . '_at'}?->toDateTime()) {
            $this->callback($data);

            return;
        }

        try {
            $zones    = (array)$this->schoolNoSql->zones;
            $lmsZones = $zones[$this->lms->uuid] ?? [];
            $newZones = [
                'id'    => $data->id,
                'title' => $data->name
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
            Log::error($this->instance . ' Sync domain: ' . $data->id . ' fail: ' . $exception->getMessage());
            Log::debug($exception);

            // Update synced_at to mongodb
            $this->callback($data, false);
        }
    }
}
