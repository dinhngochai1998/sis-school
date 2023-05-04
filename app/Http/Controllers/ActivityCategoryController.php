<?php
/**
 * @Author Edogawa Conan
 * @Date   Aug 20, 2021
 */

namespace App\Http\Controllers;

use App\Services\ActivityCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use YaangVu\LaravelBase\Controllers\BaseController;

class ActivityCategoryController extends BaseController
{
    public function __construct()
    {
        $this->service = new ActivityCategoryService();
        parent::__construct();
    }

    public function getParameterCurrentSchools(): JsonResponse
    {
        return response()->json($this->service->getParameterCurrentSchools());
    }

    public function upsertActivityCategories(Request $request): JsonResponse
    {
        return response()->json($this->service->upsertActivityCategories($request));
    }

}
