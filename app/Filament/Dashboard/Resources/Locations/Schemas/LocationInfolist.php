<?php

namespace App\Filament\Dashboard\Resources\Locations\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class LocationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextEntry::make('label'),
                TextEntry::make('type')
                    ->badge(),
                TextEntry::make('address')
                    ->columnSpanFull(),
                TextEntry::make('lat')
                    ->label('Latitude'),
                TextEntry::make('lng')
                    ->label('Longitude'),
            ]);
    }
}
