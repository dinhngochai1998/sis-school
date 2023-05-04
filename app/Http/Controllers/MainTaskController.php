<?php

namespace App\Http\Controllers;

use App\Services\MainTaskService;
use YaangVu\LaravelBase\Controllers\BaseController;

class MainTaskController extends BaseController
{
    public function __construct()
    {
        $this->service = new MainTaskService();
        parent::__construct();
    }
}
