<?php
/**
 * @Author yaangvu
 * @Date   Aug 05, 2021
 */

namespace App\Jobs;


class RabbitMQSampleQueue extends QueueJob
{

    public function handle($consumer = null, ?object $data = null)
    {
        parent::handle($consumer, $data);

    }
}
