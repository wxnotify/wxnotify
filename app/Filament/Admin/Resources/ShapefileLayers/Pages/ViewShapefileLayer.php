<?php

namespace App\Filament\Admin\Resources\ShapefileLayers\Pages;

use App\Actions\Shapefiles\ActivateShapefileVersion;
use App\Actions\Shapefiles\DismissShapefileVersion;
use App\Actions\Shapefiles\RollbackShapefileLayer;
use App\Enums\ShapefileVersionStatus;
use App\Filament\Admin\Resources\ShapefileLayers\Schemas\ShapefileLayerInfolist;
use App\Filament\Admin\Resources\ShapefileLayers\ShapefileLayerResource;
use App\Models\ShapefileLayerVersion;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewShapefileLayer extends ViewRecord
{
    protected static string $resource = ShapefileLayerResource::class;

    public function infolist(Schema $schema): Schema
    {
        return ShapefileLayerInfolist::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewRecords')
                ->label('View records')
                ->url(fn (): string => static::getResource()::getUrl('records', ['record' => $this->record])),
            Action::make('viewMap')
                ->label('View map')
                ->url(fn (): string => static::getResource()::getUrl('map', ['record' => $this->record])),
            Action::make('activate')
                ->visible(fn (): bool => $this->record->pendingVersion !== null)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(ActivateShapefileVersion::class)($this->record->pendingVersion);
                    $this->record->refresh();
                    Notification::make()->title('Layer activated')->success()->send();
                }),
            Action::make('dismiss')
                ->visible(fn (): bool => $this->record->pendingVersion !== null)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(DismissShapefileVersion::class)($this->record->pendingVersion);
                    $this->record->refresh();
                    Notification::make()->title('Version dismissed')->success()->send();
                }),
            Action::make('rollback')
                ->visible(fn (): bool => ShapefileLayerVersion::where('shapefile_layer_id', $this->record->id)
                    ->where('status', ShapefileVersionStatus::Superseded)
                    ->exists())
                ->requiresConfirmation()
                ->action(function (): void {
                    app(RollbackShapefileLayer::class)($this->record);
                    $this->record->refresh();
                    Notification::make()->title('Layer rolled back')->success()->send();
                }),
        ];
    }
}
