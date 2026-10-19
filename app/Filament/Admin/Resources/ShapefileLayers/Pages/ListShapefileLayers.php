<?php

namespace App\Filament\Admin\Resources\ShapefileLayers\Pages;

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
                        ->body('The scan runs on the queue, so the queue worker needs to be running. It shares that worker with alert delivery, so alerts wait while it runs: start it at a quiet time. You will get a notification here when it finishes; new versions then wait for your review under Shapefile Layers.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
