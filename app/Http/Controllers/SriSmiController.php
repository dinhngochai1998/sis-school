<?php


namespace App\Http\Controllers;


use App\Services\SriSmiService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JetBrains\PhpStorm\Pure;

class SriSmiController
{
    private SriSmiService $service;

    #[Pure]
    public function __construct()
    {
        $this->service = new SriSmiService();
    }

    /**
     * @param string|int $userId
     * @param string|int $gradeId
     *
     * @return JsonResponse
     */
    public function getSriSmiAssessment(string|int $userId, string|int $gradeId): JsonResponse
    {
        return response()->json($this->service->getSriSmiAssessment($userId, $gradeId));
    }

    public function getALlSriSmiAssessment(string|int $userId): JsonResponse
    {
        return response()->json($this->service->getALlSriSmiAssessment($userId));
    }

    /**
     * @param string|int $userId
     *
     * @return JsonResponse
     */
    public function getGradeSriSmiByUserId(string|int $userId): JsonResponse
    {
        return response()->json($this->service->getGradeSriSmiByUserId($userId));
    }

    /**
     * @return JsonResponse
     */
    public function getSriSmiReport(Request $request): JsonResponse
    {
        return response()->json($this->service->getSriSmiReport($request));
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse
     * @throws Exception
     */
    public function importSriSmi(Request $request): JsonResponse
    {
        return response()->json($this->service->importSriSmi($request));
    }
}
