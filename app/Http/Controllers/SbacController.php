<?php

namespace App\Http\Controllers;

use App\Services\SbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use YaangVu\LaravelBase\Controllers\BaseController;

class SbacController extends BaseController
{

    public function __construct()
    {
        $this->service = new SbacService();
        parent::__construct();

    }

    /**
     * @Author Edogawa Conan
     * @Date   Mar 29, 2022
     *
     * @param int|string $id
     *
     * @return JsonResponse
     */
    public function getUserDetailSbac(int|string $id): JsonResponse
    {
        return response()->json($this->service->getUserDetailSbac($id));
    }


    public function getTemplateSbacScore(): BinaryFileResponse
    {
        return response()->download(storage_path('/template/ACT-SBAC-Score/SBAC_Template.xlsx'));
    }


    public function importSbac(Request $request) :JsonResponse
    {
        return response()->json($this->service->importSbac($request));

    }


}
