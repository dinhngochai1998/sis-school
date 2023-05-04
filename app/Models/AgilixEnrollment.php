<?php

namespace App\Models;

use App\Jobs\SyncDataJob;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jenssegers\Mongodb\Eloquent\Model;
use YaangVu\Constant\DbConnectionConstant;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;

class AgilixEnrollment extends Model
{
    protected $table      = 'lms_agilix_enrollments';
    protected $guarded    = [];
    protected $connection = DbConnectionConstant::NOSQL;

    public function getCourseidAttribute($value): string
    {
        if ($value)
            $value = (string)$value;

        return $value;
    }

    public function getUseridAttribute($value): string
    {
        if ($value)
            $value = (string)$value;

        return $value;
    }

    public function userNoSql(): BelongsTo
    {
        return $this->belongsTo(UserNoSQL::class, 'userid', 'external_id.agilix');
    }

    public function classSql(): BelongsTo
    {
        return $this->belongsTo(ClassSQL::class, 'courseid', 'external_id')
                    ->where('lms_id', '=', SyncDataJob::$singletonLms->id) // LMS is agilix
            ;
    }
}
