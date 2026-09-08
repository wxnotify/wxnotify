<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched once per AlertRule x NotificationChannel matched by
 * App\Actions\Weather\MatchWeatherAlertRules. Takes ids rather than model
 * instances so a rule/channel/product deleted between dispatch and
 * processing is a silent, intentional no-op — not a failed job.
 *
 * This is a minimal stub: handle() is implemented in a later task.
 */
class DeliverWeatherAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $ruleId,
        public readonly int $channelId,
        public readonly int $ingestedProductId,
    ) {}

    public function handle(): void
    {
        // Implemented in a later task.
    }
}
