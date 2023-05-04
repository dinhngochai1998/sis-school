<?php
/**
 * @Author im.phien
 * @Date   Jul 11, 2022
 */

namespace App\Http\Controllers;

use App\Services\ChatSupporterService;
use Illuminate\Http\Request;
use YaangVu\LaravelBase\Controllers\BaseController;

class ChatSupporterController extends BaseController
{
    function __construct()
    {
        $this->service = new ChatSupporterService();
        parent::__construct();
    }

    public function updateSupporter(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->service->updateSupporter($request));
    }

    public function getAll(): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->service->getAll());
    }
}