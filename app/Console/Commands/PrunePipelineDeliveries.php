<?php

namespace App\Console\Commands;

use App\Models\PipelineDelivery;
use Illuminate\Console\Command;

class PrunePipelineDeliveries extends Command
{
    protected $signature = 'pipeline:prune-deliveries';

    protected $description = 'Delete pipeline delivery records older than the retention window';

    public function handle(): int
    {
        $hours = (int) config('wxnotify.pipeline.delivery_retention_hours', 48);

        $deleted = PipelineDelivery::where('received_at', '<', now()->subHours($hours))->delete();

        $this->info("Pruned {$deleted} pipeline deliveries older than {$hours} hours.");

        return self::SUCCESS;
    }
}
