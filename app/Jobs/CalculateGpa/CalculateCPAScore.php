<?php

namespace App\Jobs\CalculateGpa;

use App\Jobs\QueueJob;
use App\Services\GpaService;
use Illuminate\Support\Facades\Log;
use Throwable;

class CalculateCPAScore extends QueueJob
{
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws Throwable
     */
    public function handle($consumer = null, ?object $data = null)
    {
        Log::info('[CALCULATE RANK CPA] Data : ', (array)$data);
        (new GpaService())->calculateCpaRank($data);
    }
}
