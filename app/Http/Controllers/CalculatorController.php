<?php

namespace App\Http\Controllers;

use App\Services\GpaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use YaangVu\LaravelBase\Controllers\BaseController;
use Exception;

class CalculatorController extends BaseController
{
    public function __construct()
    {
        $this->service = new GpaService();
        parent::__construct();
    }

    /**
     * @throws Exception
     */
    public function calculateGpaScore(Request $request): JsonResponse
    {
        // Run Queue
        return response()->json($this->service->calculateGpaScore($request));

        // return response()->json($this->service->calculateScore($request));

    }


    /**
     * @throws Exception
     */
    public function calculateCpaRank(Request $request): JsonResponse
    {
        // Run Queue
        return response()->json($this->service->calculateCpaScore($request));

        // return response()->json($this->service->calculateCpaRank($request));
    }
}
