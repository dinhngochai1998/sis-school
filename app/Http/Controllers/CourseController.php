<?php

namespace App\Http\Controllers;

use App\Services\CourseService;
use YaangVu\LaravelBase\Controllers\BaseController;

class CourseController extends BaseController
{
    public function __construct()
    {
        $this->service = new CourseService();
        parent::__construct();
    }
}
