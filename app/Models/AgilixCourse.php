<?php

namespace App\Models;

use Jenssegers\Mongodb\Eloquent\Model;
use YaangVu\Constant\DbConnectionConstant;

class AgilixCourse extends Model
{
    protected $table      = 'lms_agilix_courses';
    protected $guarded    = [];
    protected $connection = DbConnectionConstant::NOSQL;
}
