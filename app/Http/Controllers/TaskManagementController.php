<?php

namespace App\Http\Controllers;

use App\Services\TaskManagementService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JetBrains\PhpStorm\Pure;

class TaskManagementController extends Controller
{
    protected TaskManagementService $service;

    public function __construct()
    {
        $this->service = new TaskManagementService();
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jun 27, 2022
     *
     * @return JsonResponse
     * @throws Exception
     */
    public function getAllListTaskManagement(Request $request): JsonResponse
    {
        return response()->json($this->service->getAllListTaskManagement($request));
    }

    /**
     * @Description
     *
     * @Author Admin
     * @Date   Jul 17, 2022
     *
     * @return JsonResponse
     */
    public function getOwnerTaskManagement(Request $request): JsonResponse
    {
        return response()->json($this->service->getOwnerTaskManagement($request));
    }
}
