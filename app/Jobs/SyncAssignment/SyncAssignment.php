<?php
/**
 * @Author yaangvu
 * @Date   Sep 24, 2021
 */

namespace App\Jobs\SyncAssignment;

use App\Jobs\SyncDataJob;

abstract class SyncAssignment extends SyncDataJob
{
    public string $jobName = 'sync_assignment';
    public int    $limit   = 500;
}