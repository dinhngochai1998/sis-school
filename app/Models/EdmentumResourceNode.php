<?php

namespace App\Models;


use Jenssegers\Mongodb\Eloquent\Model;
use YaangVu\Constant\DbConnectionConstant;

class EdmentumResourceNode extends Model
{
    protected $table      = 'lms_edmentum_resource_nodes';
    protected $guarded    = [];
    protected $connection = DbConnectionConstant::NOSQL;
}
