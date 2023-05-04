<?php

namespace App\Jobs;

use Carbon\Carbon;
use Faker\Provider\Uuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\UTCDateTime;
use YaangVu\Constant\SchoolConstant;
use YaangVu\SisModel\App\Models\impl\LmsSQL;
use YaangVu\SisModel\App\Models\impl\SchoolNoSQL;
use YaangVu\SisModel\App\Models\impl\SchoolSQL;
use YaangVu\SisModel\App\Models\Lms;
use YaangVu\SisModel\App\Models\MongoModel;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;

abstract class SyncDataJob extends Job
{
    protected string      $table;
    protected string      $lmsName;
    protected SchoolSQL   $school;
    protected SchoolNoSQL $schoolNoSql;
    protected Lms         $lms;
    protected string      $instance;
    protected string      $schoolUuid;
    public string         $jobName = 'synced';
    static Lms            $singletonLms;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($schoolUuid = SchoolConstant::IGS)
    {
        $this->schoolUuid = $schoolUuid;
        $this->instance   = Uuid::uuid();
    }

    public function handle()
    {
        try {
            $this->school                         = SchoolSQL::whereUuid($this->schoolUuid)->firstOrFail();
            $this->schoolNoSql                    = SchoolNoSQL::whereUuid($this->schoolUuid)->firstOrFail();
            $this->lms                            = LmsSQL::whereName($this->lmsName)->firstOrFail();
            self::$singletonLms                   = $this->lms;
            SchoolServiceProvider::$currentSchool = $this->school;
            $this->initSyncTime($this->table, $this->jobName);
        } catch (ModelNotFoundException $exception) {
            Log::debug($exception);

            return;
        }
    }

    /**
     * @return mixed
     */
    public abstract function getData(): mixed;

    /**co
     *
     * @param $data
     *
     * @return void
     */
    public abstract function sync($data): void;

    /**
     * Update synced status after sync data
     *
     * @param Model|MongoModel $data
     * @param bool             $status
     */
    public function callback(Model|MongoModel $data, bool $status = true): void
    {
        $data->setTable($this->table);
        $data->synced_at                    = new UTCDateTime(Carbon::now()->toDateTime());
        $data->synced_status                = $status;
        $data->{$this->jobName . '_at'}     = new UTCDateTime(Carbon::now()->toDateTime());
        $data->{$this->jobName . '_status'} = $status;
        $data->save();
    }

    protected function initSyncTime(string $table, string $jobName)
    {
        MongoModel::from($table)
                  ->whereNull($jobName . '_at')
                  ->orWhere($jobName . '_at', '=', '')
                  ->update([$jobName . '_at' => new UTCDateTime(Carbon::create(1970)->toDateTime())]);
    }
}
