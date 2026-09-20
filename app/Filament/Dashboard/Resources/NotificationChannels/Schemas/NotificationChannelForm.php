<?php

namespace App\Filament\Dashboard\Resources\NotificationChannels\Schemas;

use App\Models\NotificationChannel;
use Filament\Forms\Components\TextInput;
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
                // Read-only and never dehydrated: this is only a way to read the
                // secret back after the connect wizard, not a way to change it.
                TextInput::make('config.secret')
                    ->label('Signing secret')
                    ->password()
                    ->revealable()
                    ->readOnly()
                    ->dehydrated(false)
                    ->helperText('Verify each request by computing HMAC-SHA256 of the raw request body with this secret and comparing it to the "Signature" header.')
                    ->visible(fn (?NotificationChannel $record): bool => $record?->channel_type === 'webhook'),
            ]);
    }
}
