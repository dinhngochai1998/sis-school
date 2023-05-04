<?php

namespace App\Http\Controllers;

use App\Services\SchoolService;
use Illuminate\Http\JsonResponse;
use YaangVu\LaravelBase\Controllers\BaseController;

class SchoolController extends BaseController
{
    public function __construct()
    {
        $this->service = new SchoolService();
        parent::__construct();
    }
    /**
     * Display the specified resource.
     *
     * @param $code
     *
     * @return JsonResponse
     */
    public function showByCode($code): JsonResponse
    {
        return response()->json($this->service->getByUuid($code));
    }

    public function getCurrentSchool(): JsonResponse
    {
       return response()->json($this->service->getCurrentSchool());
    }
}
