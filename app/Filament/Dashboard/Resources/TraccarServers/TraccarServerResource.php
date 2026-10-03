<?php

namespace App\Filament\Dashboard\Resources\TraccarServers;

use App\Enums\Tier;
use App\Filament\Dashboard\Resources\TraccarServers\Pages\CreateTraccarServer;
use App\Filament\Dashboard\Resources\TraccarServers\Pages\EditTraccarServer;
use App\Filament\Dashboard\Resources\TraccarServers\Pages\ListTraccarServers;
use App\Filament\Dashboard\Resources\TraccarServers\Schemas\TraccarServerForm;
use App\Filament\Dashboard\Resources\TraccarServers\Tables\TraccarServersTable;
use App\Filament\Dashboard\Resources\TraccarServers\Widgets\TraccarDevicesMapWidget;
use App\Models\TraccarServer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TraccarServerResource extends Resource
{
    protected static ?string $model = TraccarServer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Tools';

    protected static ?string $navigationLabel = 'Traccar Servers';

    protected static ?string $modelLabel = 'Traccar server';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()->tier() !== Tier::Free;
    }

    public static function canAccess(): bool
    {
        return auth()->user()->tier() !== Tier::Free;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereBelongsTo(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return TraccarServerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TraccarServersTable::configure($table);
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
            'index' => ListTraccarServers::route('/'),
            'create' => CreateTraccarServer::route('/create'),
            'edit' => EditTraccarServer::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<class-string>
     */
    public static function getWidgets(): array
    {
        return [
            TraccarDevicesMapWidget::class,
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }
}
