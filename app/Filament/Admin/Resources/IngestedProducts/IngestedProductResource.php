<?php

namespace App\Filament\Admin\Resources\IngestedProducts;

use App\Filament\Admin\Resources\IngestedProducts\Pages\ListIngestedProducts;
use App\Filament\Admin\Resources\IngestedProducts\Pages\ViewIngestedProduct;
use App\Filament\Admin\Resources\IngestedProducts\Schemas\IngestedProductInfolist;
use App\Filament\Admin\Resources\IngestedProducts\Tables\IngestedProductsTable;
use App\Models\IngestedProduct;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class IngestedProductResource extends Resource
{
    protected static ?string $model = IngestedProduct::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Ingested Products';

    protected static ?string $modelLabel = 'ingested product';

    public static function infolist(Schema $schema): Schema
    {
        return IngestedProductInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IngestedProductsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIngestedProducts::route('/'),
            'view' => ViewIngestedProduct::route('/{record}'),
        ];
    }
}
