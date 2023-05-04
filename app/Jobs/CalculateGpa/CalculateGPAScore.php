<?php

namespace App\Jobs\CalculateGpa;

use App\Jobs\QueueJob;
use App\Services\GpaService;
use Illuminate\Support\Facades\Log;
use JetBrains\PhpStorm\NoReturn;
use Throwable;

class CalculateGPAScore extends QueueJob
{
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {

    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws Throwable
     */
    #[NoReturn]
    public function handle($consumer = null, ?object $data = null)
    {
        Log::info('[CALCULATE GPA SCORE] Data : ', (array)$data);
        (new GpaService())->calculateScore($data);

    }
}
