<?php

namespace App\Http\Controllers;

use App\Services\SubjectRuleService;
use YaangVu\LaravelBase\Controllers\BaseController;

class SubjectRuleController extends BaseController
{
    public function __construct()
    {
        $this->service = new SubjectRuleService();
        parent::__construct();
    }
}
