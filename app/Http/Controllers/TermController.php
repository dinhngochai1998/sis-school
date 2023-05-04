<?php

namespace App\Http\Controllers;

use App\Services\TermService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;
use YaangVu\LaravelBase\Controllers\BaseController;

class TermController extends BaseController
{
    public function __construct()
    {
        $this->service = new TermService();
        parent::__construct();
    }

    public function concludeTerm(int $id): JsonResponse
    {
        return response()->json($this->service->concludeTerm($id));
    }

    /**
     * @throws Throwable
     */
    public function copyTermClasses(Request $request): JsonResponse
    {
        return response()->json($this->service->copyTermClasses($request));
    }

    /**
     * @throws Throwable
     */
    public function copyOptionsTermClasses(Request $request): JsonResponse
    {
        return response()->json($this->service->copyOptionsTermClasses($request));
    }

    public function getTermsByStudentId(string $id): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->service->getTermsByStudentId($id));
    }
}
