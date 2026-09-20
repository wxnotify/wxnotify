<?php

namespace App\Filament\Dashboard\Resources\NotificationChannels\Schemas;

use App\Models\NotificationChannel;
use App\Support\ChannelTypes\WebhookChannelType;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;

class NotificationChannelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('label')
                    ->required()
                    ->maxLength(255),
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
