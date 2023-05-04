<?php
/**
 * @Author Admin
 * @Date   Apr 25, 2022
 */

namespace App\Http\Controllers;

use App\Services\GraduationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use YaangVu\LaravelBase\Controllers\BaseController;
class GraduationController extends BaseController
{
    public function __construct()
    {
        $this->service = new GraduationService();
        parent::__construct();
    }

    public function upsertGraduation(Request $request ,$userId): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->service->upsertGraduation($request ,$userId));
    }

    public function getGraduationByUserId($userId): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->service->getGraduationByUserId($userId));
    }
}