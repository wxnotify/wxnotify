<?php

namespace App\Filament\Dashboard\Resources\NotificationChannels\Tables;

use App\Actions\Channels\DispatchChannelConfirmationCode;
use App\Actions\Channels\SendTestNotification;
use App\Actions\Channels\VerifyChannelConfirmationCode;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\WebhookChannelType;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Arr;

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
                TextColumn::make('destination')
                    ->label('Destination')
                    ->state(fn (NotificationChannel $record): string => app(ChannelTypeRegistry::class)->find($record->channel_type)?->displaySummary($record) ?? '—'),
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
                Action::make('sendTest')
                    ->label('Send Test')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (NotificationChannel $record): bool => $record->isVerified())
                    ->action(function (NotificationChannel $record): void {
                        $sent = app(SendTestNotification::class)($record);

                        Notification::make()
                            ->title($sent ? 'Test notification sent' : 'Couldn\'t send the test notification')
                            ->body($sent ? null : 'Check the channel is still reachable and try again.')
                            ->status($sent ? 'success' : 'danger')
                            ->send();
                    }),
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data, NotificationChannel $record): array => $record->channel_type === 'webhook'
                        ? [...$data, ...WebhookChannelType::formFromConfig($record->config)]
                        : $data)
                    ->using(self::saveChannel(...)),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Persist an edited channel.
     *
     * For a webhook channel the flat option fields are folded back into the
     * existing encrypted `config` through the channel type, so a key the form
     * didn't show (the secret while signing is off) survives and `config` is
     * never replaced wholesale. Both subscriber-controlled URLs were already
     * re-checked by the fields' own guard rules, which surface as ordinary form
     * errors before this runs.
     *
     * For every other channel type `config` is still dropped outright: nothing
     * on their form is allowed to write it.
     *
     * @param  array<string, mixed>  $data
     */
    private static function saveChannel(NotificationChannel $record, array $data): NotificationChannel
    {
        if ($record->channel_type !== 'webhook') {
            $record->update(Arr::except($data, ['config']));

            return $record;
        }

        $previousUrl = $record->config['url'] ?? null;
        $config = WebhookChannelType::configFromForm($data, $record->config ?? []);
        $urlChanged = ($config['url'] ?? null) !== $previousUrl;

        $record->forceFill([
            'label' => $data['label'],
            'config' => $config,
            // A new endpoint has proved nothing: the subscriber confirms it again.
            ...$urlChanged ? ['verified_at' => null] : [],
        ])->save();

        if ($urlChanged) {
            // Deliberately not deleting or rolling back when this fails: unlike the
            // connect wizard there is an existing channel here, and the subscriber
            // can resend the code from this same table.
            $dispatched = app(DispatchChannelConfirmationCode::class)($record);

            Notification::make()
                ->title($dispatched
                    ? 'Confirmation code sent to the new endpoint'
                    : 'Couldn\'t send a confirmation code to the new endpoint')
                ->body($dispatched
                    ? 'Enter it with the Verify action to start delivering again.'
                    : 'The channel is saved but unverified. Use "Resend code" once the endpoint is reachable.')
                ->status($dispatched ? 'success' : 'warning')
                ->send();
        }

        return $record;
    }
}
