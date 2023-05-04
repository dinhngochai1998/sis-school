<?php

namespace App\Http\Controllers;

use App\Services\ZoomSettingService;
use Illuminate\Http\Request;
use YaangVu\LaravelBase\Controllers\BaseController;

class ZoomSettingController extends BaseController
{
    public function __construct()
    {
        $this->service = new ZoomSettingService();
        parent::__construct();
    }

    public function setupZoomSetting(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->service->setupZoomSetting($request));
    }

    public function getAllZoomSetting(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->service->getAllZoomSetting($request));
    }
}
