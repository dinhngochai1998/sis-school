<?php

namespace App\Http\Controllers;

use App\Services\SubTaskService;
use Illuminate\Http\Request;
use YaangVu\LaravelBase\Controllers\BaseController;

class SubTaskController extends BaseController
{
    public function __construct()
    {
        $this->service = new SubTaskService();
        parent::__construct();
    }

    public function createTaskIndividual(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->service->createTaskIndividual($request));
    }

    public function editTaskIndividual($id, Request $request): \Illuminate\Http\JsonResponse
    {

        return response()->json($this->service->editTaskIndividual($id, $request));
    }

    public function createSubTask(Request $request) {
        return response()->json($this->service->createSubTask($request));
    }

}
