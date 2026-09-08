<?php

namespace App\Filament\Admin\Resources\ShapefileLayers;

use App\Filament\Admin\Resources\ShapefileLayers\Pages\ListShapefileLayers;
use App\Filament\Admin\Resources\ShapefileLayers\Pages\ViewShapefileLayer;
use App\Filament\Admin\Resources\ShapefileLayers\Tables\ShapefileLayersTable;
use App\Models\ShapefileLayer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ShapefileLayerResource extends Resource
{
    protected static ?string $model = ShapefileLayer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'Geography';

    protected static ?string $navigationLabel = 'Shapefile Layers';

    protected static ?string $modelLabel = 'shapefile layer';

    public static function table(Table $table): Table
    {
        return ShapefileLayersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShapefileLayers::route('/'),
            'view' => ViewShapefileLayer::route('/{record}'),
        ];
    }
}
