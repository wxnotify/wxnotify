<?php

namespace App\Jobs;

use App\Actions\Shapefiles\ScanAllShapefileLayers;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs the shapefile scan that the owner started from the admin panel, then tells them in the
 * panel's notifications how many layers staged a new version. Nothing is activated here.
 */
class ScanShapefileLayersJob implements ShouldQueue
{
    use Queueable;

    /**
     * A full scan fetches every layer's page and downloads and imports each staged zip, which
     * can run well past the worker's 60 second default (the queue-long worker allows 900, its
     * connection's retry_after 960). It runs once: a retry would re-scan.
     */
    public int $timeout = 900;

    public int $tries = 1;

    /**
     * On the database queue the scan goes to its own connection and queue, read only by the
     * queue-long worker (compose.yaml), so it never holds the worker that delivers alerts. Any other
     * queue connection (sync in tests, a non-database install) keeps the default, as before.
     */
    public function __construct(public readonly int $userId)
    {
        if (config('queue.default') === 'database') {
            $this->onConnection('database_long')->onQueue('long');
        }
    }

    public function handle(ScanAllShapefileLayers $scan): void
    {
        $staged = $scan();

        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        Notification::make()
            ->title('Shapefile scan finished')
            ->body("{$staged} layer(s) staged a new version. Review and activate them under Shapefile Layers.")
            ->success()
            ->sendToDatabase($user);
    }

    public function failed(?Throwable $exception): void
    {
        logger()->error('ScanShapefileLayersJob failed', [
            'user_id' => $this->userId,
            'exception' => $exception?->getMessage(),
        ]);

        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        Notification::make()
            ->title('Shapefile scan failed')
            ->body('The scan did not finish. Check the logs, then press Check for shapefile updates again: it imports again any layer whose import was cut short.')
            ->danger()
            ->sendToDatabase($user);
    }
}
