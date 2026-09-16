<?php

namespace App\Filament\Dashboard\Resources\NotificationChannels\Tables;

use App\Actions\Channels\DispatchChannelConfirmationCode;
use App\Actions\Channels\VerifyChannelConfirmationCode;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\TextInput;
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
                Action::make('verify')
                    ->label('Verify')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (NotificationChannel $record): bool => ! $record->isVerified())
                    ->schema([
                        TextInput::make('code')
                            ->label('Confirmation code')
                            ->required()
                            ->helperText('Enter the code we sent to this channel. Use "Resend code" first if you need a new one.'),
                    ])
                    ->action(function (array $data, NotificationChannel $record): void {
                        $verified = app(VerifyChannelConfirmationCode::class)($record, trim((string) $data['code']));

                        if (! $verified) {
                            Notification::make()
                                ->title('That code didn\'t match')
                                ->body('Double-check the code and try again — codes expire after 15 minutes.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Channel verified')
                            ->success()
                            ->send();
                    }),
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
