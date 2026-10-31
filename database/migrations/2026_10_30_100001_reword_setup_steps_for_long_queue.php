<?php

use Illuminate\Database\Migrations\Migration;
use Wallacemartinss\FilamentOnboarding\Models\OnboardingFlow;
use Wallacemartinss\FilamentOnboarding\Models\OnboardingStep;

return new class extends Migration
{
    private const FLOW_KEY = 'owner-setup';

    private const OLD_BOUNDARIES = 'WxNotify uses NWS boundary shapefiles (counties, zones and more) to work out which places a warning covers. On Shapefile Layers, press Check for shapefile updates: the scan runs on the queue, so it needs the worker from the next step, and since that worker also delivers alerts, alerts wait while it runs (start it at a quiet time). Then review each imported layer and press Activate. If a long first scan fails, run the worker with a longer --timeout (for example 900; queue:listen defaults to 60 seconds), set DB_QUEUE_RETRY_AFTER above it and press the button again: it re-imports any layer that did not finish.';

    private const NEW_BOUNDARIES = 'WxNotify uses NWS boundary shapefiles (counties, zones and more) to work out which places a warning covers. On Shapefile Layers, press Check for shapefile updates: the scan runs on its own queue worker (queue-long, or any worker started with queue:work database_long --queue=long --timeout=900), so that worker needs to be running; alerts keep being delivered while it runs. Then review each imported layer and press Activate. If a scan fails, press the button again: it re-imports any layer that did not finish.';

    private const OLD_QUEUE = 'WxNotify needs two processes running all the time: the scheduler (a cron entry running schedule:run every minute, or schedule:work) for its timed work, and a queue worker (queue:work or queue:listen) that sends alerts and runs imports. QUEUE_CONNECTION=sync is not enough: it would run the heartbeat inside the scheduler and hide a missing worker. This ticks when the heartbeat job, dispatched every minute, was run in the last 10 minutes.';

    private const NEW_QUEUE = 'WxNotify needs these processes running all the time, each under a process manager that restarts it: the scheduler (a cron entry running schedule:run every minute, or schedule:work) for its timed work; a queue worker (queue:work --queue=deliveries,default) that processes products and sends alerts; and a second worker (queue:work database_long --queue=long --timeout=900) that only runs the shapefile scan. Restart the workers with php artisan queue:restart after every deploy. QUEUE_CONNECTION=sync is not enough: it would run the heartbeat inside the scheduler and hide a missing worker. This ticks when the heartbeat job, dispatched every minute, was run in the last 10 minutes; it does not check the shapefile worker.';

    /**
     * Rewords the boundaries and scheduler/queue steps for the separate shapefile worker. Owner
     * edits survive: each step changes only while its description still equals the shipped text.
     */
    public function up(): void
    {
        $this->reword('boundaries', self::OLD_BOUNDARIES, self::NEW_BOUNDARIES);
        $this->reword('scheduler-queue', self::OLD_QUEUE, self::NEW_QUEUE);
    }

    public function down(): void
    {
        $this->reword('boundaries', self::NEW_BOUNDARIES, self::OLD_BOUNDARIES);
        $this->reword('scheduler-queue', self::NEW_QUEUE, self::OLD_QUEUE);
    }

    private function reword(string $key, string $from, string $to): void
    {
        $flow = OnboardingFlow::query()->where('key', self::FLOW_KEY)->first();

        if ($flow === null) {
            return;
        }

        $step = OnboardingStep::query()->where('flow_id', $flow->id)->where('key', $key)->first();

        if ($step === null || ($step->description['en'] ?? null) !== $from) {
            return;
        }

        $step->update(['description' => ['en' => $to]]);
    }
};
