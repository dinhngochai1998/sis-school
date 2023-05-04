<?php


namespace App\Http\Controllers;


use App\Services\GradeScaleService;
use YaangVu\LaravelBase\Controllers\BaseController;

class GradeScaleController extends BaseController
{
    public function __construct()
    {
        $this->service = new GradeScaleService();
        parent::__construct();
    }
}
