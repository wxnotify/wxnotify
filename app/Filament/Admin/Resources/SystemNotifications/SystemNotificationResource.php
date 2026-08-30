<?php

namespace App\Filament\Admin\Resources\SystemNotifications;

use App\Filament\Admin\Resources\SystemNotifications\Pages\ListSystemNotifications;
use App\Filament\Admin\Resources\SystemNotifications\Tables\SystemNotificationsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use UnitEnum;

class SystemNotificationResource extends Resource
{
    protected static ?string $model = DatabaseNotification::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static string|UnitEnum|null $navigationGroup = 'Trust & Audit';

    protected static ?string $navigationLabel = 'System Notifications';

    protected static ?string $modelLabel = 'notification';

    public static function table(Table $table): Table
    {
        return SystemNotificationsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
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
            'index' => ListSystemNotifications::route('/'),
        ];
    }
}
