<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jenssegers\Mongodb\Eloquent\Model;
use Jenssegers\Mongodb\Relations\BelongsTo as MBelongsTo;
use YaangVu\Constant\DbConnectionConstant;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Models\SQLModel;

class EdmentumClass extends Model
{
    protected $table      = 'lms_edmentum_classes';
    protected $guarded    = [];
    protected $connection = DbConnectionConstant::NOSQL;

    public function classSql(): BelongsTo
    {
        return (new SQLModel())->belongsTo(ClassSQL::class, 'ClassId', 'external_id')
                               ->whereNull('deleted_at');
    }
}
