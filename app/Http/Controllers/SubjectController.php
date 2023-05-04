<?php

namespace App\Http\Controllers;

use App\Services\SubjectService;
use YaangVu\LaravelBase\Controllers\BaseController;

class SubjectController extends BaseController
{
    public function __construct()
    {
        $this->service = new SubjectService();
        parent::__construct();
    }

    public function syncType(){
        $this->service->syncType();
    }
}
