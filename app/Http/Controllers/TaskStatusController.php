<?php

namespace App\Http\Controllers;

use App\Services\SubTaskService;
use App\Services\TaskStatusService;
use YaangVu\LaravelBase\Controllers\BaseController;

class TaskStatusController extends BaseController
{
    public function __construct()
    {
        $this->service = new TaskStatusService();
        parent::__construct();
    }

}
