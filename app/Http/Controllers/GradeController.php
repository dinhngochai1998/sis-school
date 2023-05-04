<?php


namespace App\Http\Controllers;


use App\Services\GradeService;
use Illuminate\Http\JsonResponse;
use YaangVu\LaravelBase\Controllers\BaseController;

class GradeController extends BaseController
{
    public function __construct()
    {
        $this->service = new GradeService();
        parent::__construct();
    }
}
