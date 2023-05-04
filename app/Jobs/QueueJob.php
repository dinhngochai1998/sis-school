<?php
/**
 * @Author yaangvu
 * @Date   Aug 05, 2021
 */

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

abstract class QueueJob extends Job implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @Author yaangvu
     * @Date   Aug 05, 2021
     *
     * @param RabbitMQConsumer $consumer
     * @param object|null      $data
     */
    public function handle($consumer = null, ?object $data = null)
    {
        // Do something
    }
}
