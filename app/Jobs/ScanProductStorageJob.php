<?php

namespace App\Jobs;

use App\Actions\Storage\ScanProductStorage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Walks the product volumes and stores one storage snapshot for the admin Product Storage page. Unique, so the
 * hourly schedule and a manual run can never overlap: the scan action's own running check has a race window, the
 * unique lock is what guarantees two scans do not run at once.
 */
class ScanProductStorageJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Walking every product file can run well past the worker's 60 second default (the queue-long worker
     * allows 900, its connection's retry_after 960). It runs once: the next hourly run is the retry.
     */
    public int $timeout = 900;

    public int $tries = 1;

    /**
     * Held longer than the timeout so a scan that is cut off cannot be overlapped by the next dispatch.
     */
    public int $uniqueFor = 1200;

    /**
     * On the database queue the scan goes to its own connection and queue, read only by the queue-long worker
     * (compose.yaml), so it never holds the worker that delivers alerts. Any other queue connection keeps the default.
     */
    public function __construct()
    {
        if (config('queue.default') === 'database') {
            $this->onConnection('database_long')->onQueue('long');
        }
    }

    public function uniqueId(): string
    {
        return 'product-storage-scan';
    }

    public function handle(ScanProductStorage $scan): void
    {
        $scan();
    }

    public function failed(?Throwable $exception): void
    {
        logger()->error('ScanProductStorageJob failed', [
            'exception' => $exception?->getMessage(),
        ]);
    }
}
