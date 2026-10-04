<?php

namespace App\Filament\Dashboard\Resources\NotificationChannels\Schemas;

use App\Actions\Channels\RevealChannelUrl;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\WebhookChannelType;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use STS\FilamentImpersonate\ImpersonateManager;

class NotificationChannelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('label')
                    ->required()
                    ->maxLength(255),
                TextInput::make('apprise_url_preview')
                    ->label('Destination URL')
                    ->readOnly()
                    // Not a column and never saved: the real URL is `apprise_url`, which this form must
                    // not be able to touch, and a revealed value must never be stored.
                    ->dehydrated(false)
                    ->placeholder('••••••••••••••••')
                    ->helperText('Hidden until you reveal it. Treat it like a password: anyone who has it can send messages to this destination.')
                    ->visible(fn (?NotificationChannel $record): bool => filled($record?->apprise_url))
                    ->suffixActions([
                        Action::make('revealAppriseUrl')
                            ->label('Reveal')
                            ->icon(Heroicon::Eye)
                            ->visible(fn (Get $get): bool => blank($get('apprise_url_preview')))
                            ->disabled(fn (): bool => app(ImpersonateManager::class)->isImpersonating())
                            ->tooltip(fn (): string => app(ImpersonateManager::class)->isImpersonating()
                                ? 'Not available while impersonating a subscriber'
                                : 'Show the full URL')
                            ->action(function (?NotificationChannel $record, Set $set): void {
                                $result = $record === null
                                    ? ['url' => null, 'error' => 'This channel has no URL to reveal.']
                                    : app(RevealChannelUrl::class)($record);

                                if ($result['url'] === null) {
                                    Notification::make()->danger()->title('Couldn\'t reveal the URL')->body($result['error'])->send();

                                    return;
                                }

                                $set('apprise_url_preview', $result['url']);
                            }),
                        Action::make('hideAppriseUrl')
                            ->label('Hide')
                            ->icon(Heroicon::EyeSlash)
                            ->visible(fn (Get $get): bool => filled($get('apprise_url_preview')))
                            ->action(fn (Set $set) => $set('apprise_url_preview', null)),
                    ]),
                // The webhook options, flat (`url`, `method`, …) rather than
                // `config.*`: a dotted field dehydrates to an empty `config`
                // array whenever one of its leaves is hidden, which would
                // mass-assign over the real encrypted value and wipe it (see
                // .ai/rules/filament.md). NotificationChannelsTable's EditAction
                // converts between these fields and the config array.
                //
                // The whole group carries the channel-type condition so that no
                // individual field needs a second ->visible() on top of its own
                // (a field's last ->visible() wins, so the two would collide).
                // Deliberately no ->key(): a keyed container prefixes every child's
                // key (`webhook_options.url`), which would move the fields out from
                // under the plain names the form assertions and mutators use.
                Group::make(WebhookChannelType::editFields())
                    ->visible(fn (?NotificationChannel $record): bool => $record?->channel_type === 'webhook')
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
