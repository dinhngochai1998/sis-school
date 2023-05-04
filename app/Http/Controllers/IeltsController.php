<?php

namespace App\Http\Controllers;

use App\Services\IeltsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use YaangVu\LaravelBase\Controllers\BaseController;

class IeltsController extends BaseController
{
    public function __construct()
    {
        $this->service = new IeltsService();
        parent::__construct();
    }

    public function importIelts(Request $request)
    {
        return response()->json($this->service->importIelts($request));
    }

    public function groupTestName(Request $request): JsonResponse
    {
        return response()->json($this->service->groupTestName($request));
    }

    public function getIeltsOverall(Request $request): JsonResponse
    {
        return response()->json($this->service->getIeltsOverall($request));
    }

    public function getIeltsComponentScore(Request $request): JsonResponse
    {
        return response()->json($this->service->getIeltsComponentScore($request));
    }

    public function getIeltsTopAndBottom(Request $request): JsonResponse
    {
        return response()->json($this->service->getIeltsTopAndBottom($request));
    }

    public function getTemplateIelts(Request $request): BinaryFileResponse
    {
        return response()->download(storage_path('/template/Ielts/Template_ielts.xlsx'));
    }

    public function chartIndividualIelts($id): JsonResponse
    {
        return response()->json($this->service->chartIndividualIelts($id));
    }

}
