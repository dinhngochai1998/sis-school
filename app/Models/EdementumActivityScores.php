<?php

namespace App\Models;

use App\Jobs\SyncDataJob;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jenssegers\Mongodb\Eloquent\Model;
use Jenssegers\Mongodb\Relations\BelongsTo as MBelongsTo;
use YaangVu\Constant\DbConnectionConstant;
use YaangVu\SisModel\App\Models\impl\ClassNoSQL;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;

/**
 * @property EdementumActivities $activity
 * @property UserNoSQL           $userNoSQL
 * @property ClassNoSQL          $classNoSQL
 * @property ClassSQL            $classSQL
 */
class EdementumActivityScores extends Model
{
    protected $table      = 'lms_edmentum_activity_scores';
    protected $guarded    = [];
    protected $connection = DbConnectionConstant::NOSQL;

    public function activity(): BelongsTo|MBelongsTo
    {
        return $this->belongsTo(EdementumActivities::class, 'ResourceNodeId', 'ResourceNodeId');
    }

    public function userNoSql(): BelongsTo|MBelongsTo
    {
        return $this->belongsTo(UserNoSQL::class, 'LearnerUserId', 'external_id.edmentum');
    }

    public function classNoSql(): BelongsTo|MBelongsTo
    {
        return $this->belongsTo(ClassNoSQL::class, 'ClassId', 'edmentum_id');
    }

    public function classSql(): BelongsTo|MBelongsTo
    {
        return $this->belongsTo(ClassSQL::class, 'ClassId', 'external_id')
                    ->where('lms_id', '=', SyncDataJob::$singletonLms->id) // LMS is Edmentum
            ;
    }

    public function getClassIdAttribute($value): ?string
    {
        if ($value)
            return (string)$value;
        else
            return null;
    }

    public function getLearnerUserIdAttribute($value): ?string
    {
        if ($value)
            return (string)$value;
        else
            return null;
    }
}
