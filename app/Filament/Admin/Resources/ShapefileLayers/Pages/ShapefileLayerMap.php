<?php

namespace App\Filament\Admin\Resources\ShapefileLayers\Pages;

use App\Filament\Admin\Resources\ShapefileLayers\ShapefileLayerResource;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

class ShapefileLayerMap extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ShapefileLayerResource::class;

    protected string $view = 'filament.admin.pages.shapefile-layer-map';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }
}
