<?php

namespace App\Filament\Admin\Resources\ActivityLogs\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Spatie\Activitylog\Models\Activity;

class ActivityInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextEntry::make('log_name')->badge(),
                TextEntry::make('event')->badge(),
                TextEntry::make('description'),
                TextEntry::make('created_at')->dateTime(),
                KeyValueEntry::make('properties')
                    ->label('Properties')
                    ->getStateUsing(fn (Activity $record): array => $record->properties->all()),
                KeyValueEntry::make('attribute_changes')
                    ->label('Attribute changes')
                    ->getStateUsing(fn (Activity $record): array => $record->attribute_changes->all()),
            ]);
    }
}
