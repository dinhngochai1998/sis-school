<?php
/**
 * @Author Pham Van Tien
 * @Date   Mar 22, 2022
 */

namespace App\Http\Controllers;

use App\Services\ToeflService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use YaangVu\LaravelBase\Controllers\BaseController;

class ToeflController extends BaseController
{
    public function __construct()
    {
        $this->service = new ToeflService();
        parent::__construct();
    }

    public function importToefl(Request $request)
    {
        return response()->json($this->service->importToefl($request));
    }

    public function getToeflTotalScore(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->service->getToeflTotalScore($request));
    }

    public function getToeflComponentScore(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->service->getToeflComponentScore($request));
    }

    public function getToeflTopAndBottom(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->service->getToeflTopAndBottom($request));
    }

    public function getTemplateToefl(): BinaryFileResponse
    {
        return response()->download(storage_path('/template/TOEFL/Template_TOEFL.xlsx'));
    }

    public function getToefl($userId): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->service->getToeflIndividual($userId));
    }

    public function groupTestName(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->service->groupTestName($request));
    }
}