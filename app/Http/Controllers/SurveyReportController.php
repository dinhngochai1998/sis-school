<?php

namespace App\Http\Controllers;

use App\Services\SurveyReportService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use YaangVu\LaravelBase\Controllers\BaseController;

class SurveyReportController extends BaseController
{
    public function __construct()
    {
        $this->service = new SurveyReportService();
        parent::__construct();
    }

    public function surveySummarizeReport(Request $request): JsonResponse
    {
        return response()->json($this->service->reportSurveySummarize($request));
    }

    public function reportQuestion(Request $request): JsonResponse
    {
        return response()->json($this->service->getDetailAnswerQuestion($request));
    }

    public function reportSurveyIndividual($id, Request $request):JsonResponse
    {
        return response()->json($this->service->reportSurveyIndividual($id, $request));
    }

    /**
     * @throws Exception
     */
    public function exportSurvey($id, Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        return $this->service->exportSurvey($id, $request);
    }
}
