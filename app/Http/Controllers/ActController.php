<?php

namespace App\Http\Controllers;

use App\Services\ActService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use YaangVu\LaravelBase\Controllers\BaseController;

class ActController extends BaseController

{
    public function __construct()
    {
        $this->service = new ActService();
        parent::__construct();
    }


    public function getTemplateACTScore(): BinaryFileResponse
    {
        return response()->download(storage_path('/template/ACT-SBAC-Score/ACT_Template.xlsx'));
    }

    /**
     * @return JsonResponse
     */


    /**
     * @throws \Exception
     */
    public function importAct(Request $request): JsonResponse
    {
        return response()->json($this->service->importAct($request));

    }

    /**
     * @Author Edogawa Conan
     * @Date   Mar 29, 2022
     *
     * @param string|int $id
     *
     * @return JsonResponse
     */
    public function getUserDetailAct(string|int $id): JsonResponse
    {
        return response()->json($this->service->getUserDetailAct($id));
    }
}
