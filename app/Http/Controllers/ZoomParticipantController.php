<?php

namespace App\Http\Controllers;

use App\Services\ZoomMeetingService;
use App\Services\ZoomParticipantService;
use Illuminate\Http\Request;
use YaangVu\LaravelBase\Controllers\BaseController;

class ZoomParticipantController extends BaseController
{
    public function __construct()
    {
        $this->service = new ZoomParticipantService();
        parent::__construct();
    }
}
