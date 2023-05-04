<?php

namespace App\Http\Controllers;

use App\Services\ClassActivityCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;
use YaangVu\LaravelBase\Controllers\BaseController;

class ClassActivityCategoryController extends BaseController
{
    public function __construct()
    {
        $this->service = new ClassActivityCategoryService();
        parent::__construct();
    }

    public function getViaClassId(int $id): JsonResponse
    {
        return response()->json($this->service->getViaClassId($id));
    }

    public function insertBatch(int $id ,Request $request): JsonResponse
    {
        return response()->json($this->service->insertBatch ($id ,$request))->setStatusCode(ResponseAlias::HTTP_CREATED);
    }
}
