<?php


namespace App\Http\Controllers;


use App\Services\DivisionService;
use Illuminate\Http\JsonResponse;
use YaangVu\LaravelBase\Controllers\BaseController;

class DivisionController extends BaseController
{
    public function __construct()
    {
        $this->service = new DivisionService();
        parent::__construct();
    }

    /**
     * @param int|string $id
     *
     * @return JsonResponse
     */
    public function getViaSchoolId(int|string $id): JsonResponse
    {
        return response()->json($this->service->getViaSchoolId($id));
    }
}
