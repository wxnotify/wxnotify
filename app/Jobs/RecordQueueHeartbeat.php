<?php

namespace App\Jobs;

use App\Support\Setup\QueueHeartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled every minute. When a queue worker runs it, the timestamp it records shows the owner
 * that both the scheduler and the queue are running (see QueueHeartbeat).
 */
class RecordQueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        QueueHeartbeat::record();
    }
}
