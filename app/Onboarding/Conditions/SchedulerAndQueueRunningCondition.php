<?php

namespace App\Onboarding\Conditions;

use App\Support\Setup\QueueHeartbeat;
use Illuminate\Database\Eloquent\Model;

/**
 * The queue heartbeat job ran within the last 10 minutes.
 */
final class SchedulerAndQueueRunningCondition extends SafeCondition
{
    public static function label(): string
    {
        return 'The scheduler and queue worker are running';
    }

    protected function check(Model $subject): bool
    {
        $lastRanAt = QueueHeartbeat::lastRanAt();

        return $lastRanAt !== null && $lastRanAt->greaterThanOrEqualTo(now()->subMinutes(10));
    }
}
