<?php

namespace App\Filament\Admin\Resources\InviteCodes;

use App\Filament\Admin\Resources\InviteCodes\Pages\ListInviteCodes;
use App\Filament\Admin\Resources\InviteCodes\Tables\InviteCodesTable;
use App\Models\InviteCode;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class InviteCodeResource extends Resource
{
    protected static ?string $model = InviteCode::class;

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?string $navigationLabel = 'Invite codes';

    protected static ?string $modelLabel = 'invite code';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Subscribers';

    public static function table(Table $table): Table
    {
        return InviteCodesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInviteCodes::route('/'),
        ];
    }
}
