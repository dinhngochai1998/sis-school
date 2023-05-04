<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use YaangVu\Constant\DbConnectionConstant;
use YaangVu\SisModel\App\Models\impl\ClassSQL;
use YaangVu\SisModel\App\Models\SQLModel;

class AgillixClass extends Model
{
    protected $table      = 'lms_agilix_courses';
    protected $guarded    = [];
    protected $connection = DbConnectionConstant::NOSQL;

    public function classSql(): BelongsTo
    {
        return (new SQLModel())->belongsTo(ClassSQL::class, 'id', 'external_id')
                               ->whereNull('deleted_at');
    }
}
