<?php

namespace App\Http\Controllers;

use App\Services\PhysicalPerformanceMeasuresService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JetBrains\PhpStorm\NoReturn;
use YaangVu\LaravelBase\Controllers\BaseController;

class PhysicalPerformanceMeasuresController extends BaseController
{
    public function __construct()
    {
        $this->service = new PhysicalPerformanceMeasuresService();

        parent::__construct();
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse
     * @throws Exception
     */
    #[NoReturn]
    public function importPhysicalPerformanceMeasures(Request $request): JsonResponse
    {
        return response()->json($this->service->importPhysicalPerformanceMeasures($request));
    }

    public function getTemplatePhysicalPerformanceMeasures(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        return response()->download(storage_path('/template/PhysicalPerformanceMeasures/PhysicalPerformanceMeasures.xlsx'));

    }

}
