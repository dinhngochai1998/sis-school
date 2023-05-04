<?php
/**
 * @Author Admin
 * @Date   Aug 01, 2022
 */

namespace App\Http\Controllers;

use App\Services\StateService;
use YaangVu\LaravelBase\Controllers\BaseController;

class StateController extends BaseController
{
    public function __construct()
    {
        $this->service = new StateService();
        parent::__construct();
    }
}