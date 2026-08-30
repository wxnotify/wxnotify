<?php

namespace App\Filament\Admin\Resources\Users\RelationManagers;

use App\Models\NotificationChannel;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NotificationChannelsRelationManager extends RelationManager
{
    protected static string $relationship = 'notificationChannels';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                TextColumn::make('label'),
                TextColumn::make('channel_type')
                    ->label('Type')
                    ->formatStateUsing(fn (string $state): string => app(ChannelTypeRegistry::class)->find($state)?->label() ?? $state)
                    ->badge(),
                IconColumn::make('verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->getStateUsing(fn (NotificationChannel $record): bool => $record->isVerified()),
                TextColumn::make('created_at')->dateTime(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
