<?php
/**
 * @Author im.phien
 * @Date   Jun 22, 2022
 */

namespace App\Http\Controllers;

use App\Services\ZoomHostService;
use Illuminate\Http\Request;
use YaangVu\LaravelBase\Controllers\BaseController;

class ZoomHostController extends BaseController
{
    public function __construct()
    {
        $this->service = new ZoomHostService();
        parent::__construct();
    }

    public function getAllZoomHost(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->service->getAllZoomHost($request));
    }
}