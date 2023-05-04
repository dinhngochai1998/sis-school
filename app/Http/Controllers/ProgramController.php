<?php


namespace App\Http\Controllers;


use App\Services\ProgramService;
use YaangVu\LaravelBase\Controllers\BaseController;

class ProgramController extends BaseController
{
    public function __construct()
    {
        $this->service = new ProgramService();
        parent::__construct();
    }
}
