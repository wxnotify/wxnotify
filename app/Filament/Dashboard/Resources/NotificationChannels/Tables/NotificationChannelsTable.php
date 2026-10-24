<?php

namespace App\Filament\Dashboard\Resources\NotificationChannels\Tables;

use App\Actions\Channels\DispatchChannelConfirmationCode;
use App\Actions\Channels\SendTestNotification;
use App\Actions\Channels\VerifyChannelConfirmationCode;
use App\Exceptions\ChannelCodeThrottled;
use App\Models\NotificationChannel;
use App\Support\AlertNameSummary;
use App\Support\ChannelDeletionGuard;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\ByoChannelType;
use App\Support\ChannelTypes\WebhookChannelType;
use App\Support\Delivery\ChannelDispatcher;
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
                    ->state(fn (NotificationChannel $record): string => app(ChannelTypeRegistry::class)->find($record->channel_type)?->displaySummary($record) ?? '—')
                    ->description(function (NotificationChannel $record): ?string {
                        $type = app(ChannelTypeRegistry::class)->find($record->channel_type);

                        return $type !== null && $type->displaySummary($record) !== '—' ? $type->destinationLabel() : null;
                    }),
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
                    ->action(fn (NotificationChannel $record) => self::resendCode($record)),
                Action::make('sendTest')
                    ->label('Send Test')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (NotificationChannel $record): bool => $record->isVerified())
                    ->action(function (NotificationChannel $record): void {
                        $sent = app(SendTestNotification::class)($record);

                        Notification::make()
                            ->title($sent ? 'Test notification sent' : 'Couldn\'t send the test notification')
                            ->body(match (true) {
                                $sent => null,
                                ! $record->user->meetsChannelTier($record->channel_type) => ChannelDispatcher::TIER_REFUSAL,
                                // A Web Push subscription that expired during this very send is unconfirmed now, and a
                                // code would go to the dead subscription: the browser has to subscribe again.
                                ! $record->isVerified() && $record->channel_type === 'webpush' => 'This browser\'s Web Push subscription is no longer valid. Re-enable Web Push from Notification Channels by connecting the channel again.',
                                // The dispatcher refuses every other unconfirmed channel.
                                ! $record->isVerified() => 'This channel needs to be confirmed first. Resend the code and enter it.',
                                default => 'Check the channel is still reachable and try again.',
                            })
                            ->status($sent ? 'success' : 'danger')
                            ->send();
                    }),
                EditAction::make()
                    // The modal's state is serialized into the browser's Livewire snapshot, so it is built
                    // from an allow-list (the label, plus the webhook option fields) instead of the record's
                    // attributes: no secret column (the Apprise URL, the confirmation code, the decrypted
                    // config) ever travels. The owner gets the Apprise URL only through the Reveal action
                    // (RevealChannelUrl). A new editable field must be added here explicitly.
                    ->mutateRecordDataUsing(fn (NotificationChannel $record): array => [
                        'label' => $record->label,
                        'channel_type' => $record->channel_type,
                        ...($record->channel_type === 'webhook' ? WebhookChannelType::formFromConfig($record->config) : []),
                    ])
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
     * Send a fresh code and say what happened: a channel the plan no longer covers is refused
     * before anything is sent, a phone number past its code limit gets the wait, and a send the
     * provider or the notification service refused gets the type's own hint.
     */
    private static function resendCode(NotificationChannel $record): void
    {
        $type = app(ChannelTypeRegistry::class)->find($record->channel_type);
        $failed = Notification::make()->title('Couldn\'t resend the confirmation code')->danger();

        if (! $record->user->meetsChannelTier($record->channel_type)) {
            $failed->body(ChannelDispatcher::TIER_REFUSAL)->send();

            return;
        }

        try {
            $sent = app(DispatchChannelConfirmationCode::class)($record);
        } catch (ChannelCodeThrottled $e) {
            Notification::make()->title(ChannelCodeThrottled::TITLE)->body($e->hint())->danger()->send();

            return;
        }

        if (! $sent) {
            $failed->body($type instanceof ByoChannelType
                ? $type->codeNotSentHint()
                : 'The notification service is temporarily unavailable. Please try again in a moment.')
                ->send();

            return;
        }

        Notification::make()
            ->title('Confirmation code resent')
            ->success()
            ->send();
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
