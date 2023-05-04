<?php

namespace App\Jobs;

use Faker\Provider\Uuid;
use Log;

class UnknownQueue extends Job
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
     * @Author yaangvu
     * @Date   Aug 05, 2021
     *
     * @param RabbitMQConsumer $consumer
     * @param object|null      $data
     */
    public function handle(RabbitMQConsumer $consumer, object|null $data = null)
    {
        Log::info(Uuid::uuid() . ' UnknownQueue Job', (array)$data);
    }
}
