<?php
/**
 * @Author Admin
 * @Date   Mar 18, 2022
 */

namespace App\Http\Controllers;

use App\Services\SatService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JetBrains\PhpStorm\NoReturn;
use YaangVu\LaravelBase\Controllers\BaseController;

class SatController extends BaseController
{
    public function __construct()
    {
        $this->service = new SatService();

        parent::__construct();
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse
     * @throws Exception
     */
    #[NoReturn]
    public function importSat(Request $request): JsonResponse
    {
        return response()->json($this->service->importSat($request));
    }

    public function getTemplateSat(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        return response()->download(storage_path('/template/Sat/Sat.xlsx'));

    }

}