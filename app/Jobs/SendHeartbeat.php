<?php

namespace App\Jobs;

use GuzzleHttp\Exception\GuzzleException;
use YaangVu\EurekaClient\EurekaProvider;

class SendHeartbeat extends Job
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
     * @throws GuzzleException
     */
    public function handle()
    {
        EurekaProvider::$client->heartBeat();
    }
}
