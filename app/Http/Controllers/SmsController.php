<?php
/**
 * @Author Admin
 * @Date   Jul 12, 2022
 */

namespace App\Http\Controllers;

use App\Services\SmsService;
use YaangVu\LaravelBase\Controllers\BaseController;

class SmsController extends BaseController
{
    public function __construct()
    {
        $this->service = new SmsService();
        parent::__construct();
    }
}