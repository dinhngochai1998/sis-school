<?php

namespace App\Http\Controllers;

use App\Services\CommunicationServices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use YaangVu\LaravelBase\Controllers\BaseController;

class CommunicationController extends BaseController
{
    public function __construct()
    {
        $this->service = new CommunicationServices();
        parent::__construct();
    }
}
