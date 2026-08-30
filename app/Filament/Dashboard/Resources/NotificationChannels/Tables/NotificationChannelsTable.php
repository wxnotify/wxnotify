<?php

namespace App\Filament\Dashboard\Resources\NotificationChannels\Tables;

use App\Actions\Channels\DispatchChannelConfirmationCode;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NotificationChannelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->searchable(),
                TextColumn::make('channel_type')
                    ->label('Type')
                    ->formatStateUsing(fn (string $state): string => app(ChannelTypeRegistry::class)->find($state)?->label() ?? $state)
                    ->badge(),
                IconColumn::make('verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->getStateUsing(fn (NotificationChannel $record): bool => $record->isVerified()),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('resend')
                    ->label('Resend code')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (NotificationChannel $record): bool => ! $record->isVerified())
                    ->action(function (NotificationChannel $record) {
                        app(DispatchChannelConfirmationCode::class)($record);

                        Notification::make()
                            ->title('Confirmation code resent')
                            ->success()
                            ->send();
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
