<?php

namespace App\Models;

use Jenssegers\Mongodb\Eloquent\Model;
use YaangVu\Constant\DbConnectionConstant;

class AgilixRole extends Model
{
    protected $table = 'lms_agilix_roles';
    protected $connection = DbConnectionConstant::NOSQL;
}
