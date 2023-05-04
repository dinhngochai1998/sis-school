<?php

namespace App\Http\Controllers;

use App\Services\SubTaskService;
use App\Services\TaskCommentService;
use Illuminate\Http\Request;
use YaangVu\LaravelBase\Controllers\BaseController;

class TaskCommentController extends BaseController
{
    public function __construct()
    {
        $this->service = new TaskCommentService();
        parent::__construct();
    }
}
