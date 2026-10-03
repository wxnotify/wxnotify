<?php

namespace App\Filament\Dashboard\Resources\NotificationChannels\Tables;

use App\Actions\Channels\DispatchChannelConfirmationCode;
use App\Actions\Channels\SendTestNotification;
use App\Actions\Channels\VerifyChannelConfirmationCode;
use App\Models\NotificationChannel;
use App\Support\AlertNameSummary;
use App\Support\ChannelDeletionGuard;
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
use Illuminate\Support\Collection;

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
                self::guardedDeleteAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::guardedDeleteBulkAction(),
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

    /**
     * Refuses to delete a channel that is some alert's only one (it would
     * leave that alert unable to deliver). A channel shared with other
     * channels on every alert it serves is deleted, and the confirmation says
     * how many other alerts it will be removed from.
     */
    public static function guardedDeleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription(function (NotificationChannel $record): string {
                $others = ChannelDeletionGuard::alertsKeepingOtherChannels($record);

                if ($others === 0) {
                    return __('filament-actions::modal.confirmation');
                }

                return $others === 1
                    ? 'It will also be removed from 1 other alert; that alert keeps its other channels.'
                    : "It will also be removed from {$others} other alerts; they keep their other channels.";
            })
            ->before(function (DeleteAction $action, NotificationChannel $record): void {
                $alerts = ChannelDeletionGuard::alertsLeftWithoutChannel($record);

                if ($alerts->isEmpty()) {
                    return;
                }

                Notification::make()
                    ->danger()
                    ->persistent()
                    ->title("Can't delete {$record->label}")
                    ->body('It\'s the only channel for: '.AlertNameSummary::of($alerts).'. Add another channel to those alerts first.')
                    ->send();

                $action->cancel();
            });
    }

    /**
     * Deletes the channels that are safe and reports the ones that would
     * strand an alert. Records are checked one at a time against the current
     * state, so selecting every channel of an alert deletes all but the last.
     */
    public static function guardedDeleteBulkAction(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->using(function (DeleteBulkAction $action, Collection $records): void {
                foreach ($records as $record) {
                    $alerts = ChannelDeletionGuard::alertsLeftWithoutChannel($record);

                    if ($alerts->isNotEmpty()) {
                        $action->reportBulkProcessingFailure(
                            key: "only-channel-{$record->id}",
                            message: e($record->label).' is the only channel for: '.AlertNameSummary::of($alerts).'.',
                        );

                        continue;
                    }

                    $record->delete() || $action->reportBulkProcessingFailure();
                }
            });
    }
}
