<?php

namespace App\Models;

use Jenssegers\Mongodb\Eloquent\Model;
use YaangVu\Constant\DbConnectionConstant;

class EdementumActivities extends Model
{
    protected $table      = 'lms_edmentum_activities';
    protected $guarded    = [];
    protected $connection = DbConnectionConstant::NOSQL;
}
