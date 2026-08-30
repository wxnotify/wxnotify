<?php

namespace App\Filament\Admin\Resources\DeliveryLogs;

use App\Filament\Admin\Resources\DeliveryLogs\Pages\ListDeliveryLogs;
use App\Filament\Admin\Resources\DeliveryLogs\Tables\DeliveryLogsTable;
use App\Models\DeliveryLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class DeliveryLogResource extends Resource
{
    protected static ?string $model = DeliveryLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Delivery Log';

    protected static ?string $modelLabel = 'delivery log';

    public static function table(Table $table): Table
    {
        return DeliveryLogsTable::configure($table);
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
            'index' => ListDeliveryLogs::route('/'),
        ];
    }
}
