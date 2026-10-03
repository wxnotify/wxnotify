<?php

namespace App\Filament\Dashboard\Resources\NotificationChannels;

use App\Filament\Dashboard\Resources\NotificationChannels\Pages\ListNotificationChannels;
use App\Filament\Dashboard\Resources\NotificationChannels\Schemas\NotificationChannelForm;
use App\Filament\Dashboard\Resources\NotificationChannels\Tables\NotificationChannelsTable;
use App\Models\NotificationChannel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class NotificationChannelResource extends Resource
{
    protected static ?string $model = NotificationChannel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Alerting';

    protected static ?string $navigationLabel = 'Notification Channels';

    protected static ?string $modelLabel = 'channel';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereBelongsTo(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return NotificationChannelForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NotificationChannelsTable::configure($table);
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
            'index' => ListNotificationChannels::route('/'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }
}
