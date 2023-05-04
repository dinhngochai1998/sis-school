<?php


namespace App\Http\Controllers;


use App\Services\LMSService;
use Illuminate\Http\JsonResponse;
use YaangVu\LaravelBase\Controllers\BaseController;

class LMSController extends BaseController
{
    public function __construct()
    {
        $this->service = new LMSService();
        parent::__construct();
    }

    public function getCoursesViaLmsId(int $id): JsonResponse
    {
        return response()->json($this->service->getCoursesViaLmsId($id));
    }

    public function getZonesViaId(int $id): JsonResponse
    {
        return response()->json($this->service->getZonesViaId($id));
    }
}
