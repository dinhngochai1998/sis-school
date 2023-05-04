<?php

namespace App\Http\Controllers;

use App\Services\SmsParticipantService;
use Illuminate\Http\Request;
use YaangVu\LaravelBase\Controllers\BaseController;

class SmsParticipantController extends BaseController
{
    public function __construct()
    {
        $this->service = new SmsParticipantService();
        parent::__construct();
    }

    public function ReportSms($id): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->service->reportSms($id));
    }

    public function hookStatusSms(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->service->hookStatusSms($request));
    }
}
