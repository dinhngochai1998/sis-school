<?php
/**
 * @Author yaangvu
 * @Date   Sep 18, 2021
 */

namespace App\Queues;

use App\Jobs\QueueJob;
use App\Jobs\SyncClass\SyncClassAgilix;
use App\Jobs\SyncClass\SyncClassEdmentum;
use Log;
use YaangVu\Constant\LmsSystemConstant;

class SyncClassQueueFactory extends QueueJob
{
    public function handle($consumer = null, ?object $data = null)
    {
        $lms = $data->lms ?? null;
        switch ($lms) {
            case LmsSystemConstant::EDMENTUM:
                dispatch(new SyncClassEdmentum());
                break;
            case LmsSystemConstant::AGILIX:
                dispatch(new SyncClassAgilix());
                break;
            default:
                Log::info("LMS system not available: $lms");
                break;
        }
    }
}