<?php

namespace App\Jobs;

use App\Actions\Shapefiles\ScanAllShapefileLayers;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs the shapefile scan that the owner started from the admin panel, then tells them in the
 * panel's notifications how many layers staged a new version. Nothing is activated here.
 */
class ScanShapefileLayersJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId) {}

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
}
