<?php

namespace App\Models;

use Jenssegers\Mongodb\Eloquent\Model;
use YaangVu\Constant\DbConnectionConstant;

class EdementumUser extends Model
{
    protected $table = 'lms_edmentum_users';
    protected $connection = DbConnectionConstant::NOSQL;
}
