<?php

namespace App\Http\Controllers;

use App\Services\SmsSettingService;
use YaangVu\LaravelBase\Controllers\BaseController;

class SmsSettingController extends BaseController
{
    public function __construct()
    {
        $this->service = new SmsSettingService();
        parent::__construct();
    }

}
