<?php
/**
 * @Author im.phien
 * @Date   Sep 06, 2022
 */

namespace App\Http\Controllers;

use App\Services\SubjectTypeService;
use YaangVu\LaravelBase\Controllers\BaseController;

class SubjectTypeController extends BaseController
{
    public function __construct()
    {
        $this->service = new SubjectTypeService();
        parent::__construct();
    }
}