<?php

namespace App\Jobs\SyncZone;

use App\Jobs\SyncDataJob;

abstract class SyncZone extends SyncDataJob
{
    public string $jobName = 'sync_zone';
    public int    $limit   = 500;
}
