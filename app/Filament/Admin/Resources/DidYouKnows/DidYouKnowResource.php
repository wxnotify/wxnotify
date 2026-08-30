<?php

namespace App\Filament\Admin\Resources\DidYouKnows;

use App\Filament\Admin\Resources\DidYouKnows\Pages\CreateDidYouKnow;
use App\Filament\Admin\Resources\DidYouKnows\Pages\EditDidYouKnow;
use App\Filament\Admin\Resources\DidYouKnows\Pages\ListDidYouKnows;
use App\Filament\Admin\Resources\DidYouKnows\Schemas\DidYouKnowForm;
use App\Filament\Admin\Resources\DidYouKnows\Tables\DidYouKnowsTable;
use App\Models\DidYouKnow;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DidYouKnowResource extends Resource
{
    protected static ?string $model = DidYouKnow::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Did You Know?';

    protected static ?string $modelLabel = 'fact';

    public static function form(Schema $schema): Schema
    {
        return DidYouKnowForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DidYouKnowsTable::configure($table);
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
            'index' => ListDidYouKnows::route('/'),
            'create' => CreateDidYouKnow::route('/create'),
            'edit' => EditDidYouKnow::route('/{record}/edit'),
        ];
    }
}
