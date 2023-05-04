<?php

namespace App\Http\Controllers;

use App\Services\IeltsExamService;
use YaangVu\LaravelBase\Controllers\BaseController;

class IeltsExamController extends BaseController
{
    public function __construct()
    {
        $this->service = new IeltsExamService();
        parent::__construct();
    }

}
