<?php

namespace App\Jobs\SyncScore;

use App\Jobs\SyncDataJob;

abstract class SyncScore extends SyncDataJob
{
    public string $jobName = 'sync_score';
    public int    $limit   = 300;
}
