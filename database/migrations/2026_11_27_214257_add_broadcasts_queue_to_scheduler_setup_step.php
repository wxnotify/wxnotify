<?php

use Illuminate\Database\Migrations\Migration;
use Wallacemartinss\FilamentOnboarding\Models\OnboardingFlow;
use Wallacemartinss\FilamentOnboarding\Models\OnboardingStep;

return new class extends Migration
{
    private const OLD_QUEUE = 'WxNotify needs these processes running all the time, each under a process manager that restarts it: the scheduler (a cron entry running schedule:run every minute, or schedule:work) for its timed work; a queue worker (queue:work --queue=deliveries,default) that processes products and sends alerts; and a second worker (queue:work database_long --queue=long --timeout=900) that only runs the shapefile scan. Restart the workers with php artisan queue:restart after every deploy. QUEUE_CONNECTION=sync is not enough: it would run the heartbeat inside the scheduler and hide a missing worker. This ticks when the heartbeat job, dispatched every minute, was run in the last 10 minutes; it does not check the shapefile worker.';

    private const NEW_QUEUE = 'WxNotify needs these processes running all the time, each under a process manager that restarts it: the scheduler (a cron entry running schedule:run every minute, or schedule:work) for its timed work; a queue worker (queue:work --queue=deliveries,default,broadcasts) that processes products, sends alerts, and sends live dashboard updates and the iPhone badge updates for quiet-time alerts; and a second worker (queue:work database_long --queue=long --timeout=900) that only runs the shapefile scan. Restart the workers with php artisan queue:restart after every deploy. QUEUE_CONNECTION=sync is not enough: it would run the heartbeat inside the scheduler and hide a missing worker. This ticks when the heartbeat job, dispatched every minute, was run in the last 10 minutes; it does not check the shapefile worker.';

    /**
     * Adds the `broadcasts` queue to the worker command in the scheduler/queue setup step: without it a
     * hand-run worker sends no live dashboard updates and no quiet-time badge updates. Owner edits
     * survive: the step changes only while its description still equals the shipped text.
     */
    public function up(): void
    {
        $this->reword(self::OLD_QUEUE, self::NEW_QUEUE);
    }

    public function down(): void
    {
        $this->reword(self::NEW_QUEUE, self::OLD_QUEUE);
    }

    private function reword(string $from, string $to): void
    {
        $flow = OnboardingFlow::query()->where('key', 'owner-setup')->first();

        if ($flow === null) {
            return;
        }

        $step = OnboardingStep::query()->where('flow_id', $flow->id)->where('key', 'scheduler-queue')->first();

        if ($step === null || ($step->description['en'] ?? null) !== $from) {
            return;
        }

        $step->update(['description' => ['en' => $to]]);
    }
};
