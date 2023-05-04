<?php
/**
 * @Author Admin
 * @Date   May 26, 2022
 */

namespace App\Http\Controllers;

use App\Services\SurveyService;
use YaangVu\LaravelBase\Controllers\BaseController;

class SurveyController extends BaseController
{
    public function __construct()
    {
        $this->service = new SurveyService();
        parent::__construct();
    }

    /**
     * @throws \Exception
     */
    public function getDetailSurvey(string $surveyId, string $hash): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->service->getDetailSurvey($surveyId, $hash));
    }

}
