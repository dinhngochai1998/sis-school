<?php
/**
 * @Author Edogawa Conan
 * @Date   May 30, 2022
 */

namespace App\Http\Controllers;

use App\Services\ActivityClassLmsService;
use YaangVu\LaravelBase\Controllers\BaseController;

class ActivityClassLmsController extends BaseController
{
    public function __construct() {
        $this->service = new ActivityClassLmsService();
        parent::__construct();
    }
}
