<?php

namespace App\Filament\Admin\Resources\ShapefileLayers\Pages;

use App\Actions\Shapefiles\ActivateAllShapefileVersions;
use App\Filament\Admin\Resources\ShapefileLayers\ShapefileLayerResource;
use App\Jobs\ScanShapefileLayersJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListShapefileLayers extends ListRecords
{
    protected static string $resource = ShapefileLayerResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('scanForUpdates')
                ->label('Check for shapefile updates')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function (): void {
                    ScanShapefileLayersJob::dispatch(auth()->id());

                    Notification::make()
                        ->title('Scan started')
                        ->body('The scan runs on its own queue worker (queue-long), so that worker needs to be running. Alerts keep being delivered while it runs. You will get a notification here when it finishes; new versions then wait for your review under Shapefile Layers.')
                        ->success()
                        ->send();
                }),
            Action::make('activateAll')
                ->label('Activate all imported layers')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->visible(fn (): bool => ActivateAllShapefileVersions::hasImportedPendingVersion())
                ->requiresConfirmation()
                ->modalHeading('Activate all imported layers?')
                ->modalDescription('Every layer whose scan has finished importing goes live now, in one step. This is what a new install needs. On a server that already uses these boundaries, each layer replaces its current version (review a layer first if you need to; you can roll a layer back from its own page). Layers whose import did not finish are left alone.')
                ->action(function (ActivateAllShapefileVersions $activateAll): void {
                    $result = $activateAll();

                    $notification = Notification::make()
                        ->title(match (true) {
                            $result['failed'] > 0 => "Activated {$result['activated']} layer(s); {$result['failed']} failed",
                            default => "Activated {$result['activated']} layer(s)",
                        })
                        ->body($result['skipped'] > 0 ? "{$result['skipped']} layer(s) have not finished importing: press Check for shapefile updates again to finish them." : null);

                    ($result['failed'] > 0 ? $notification->danger() : $notification->success())->send();
                }),
        ];
    }
}
