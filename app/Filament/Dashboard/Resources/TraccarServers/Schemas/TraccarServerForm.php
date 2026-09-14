<?php

namespace App\Filament\Dashboard\Resources\TraccarServers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

class TraccarServerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components(self::fields());
    }

    /**
     * @return array<Component>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('label')
                ->label('Name this server')
                ->placeholder('My Traccar Server')
                ->required()
                ->maxLength(255),
            TextInput::make('base_url')
                ->label('Server URL')
                ->url()
                ->placeholder('https://demo.traccar.org')
                ->required()
                ->maxLength(255),
            TextInput::make('api_token')
                ->label('API token')
                ->password()
                ->revealable()
                ->required()
                ->maxLength(1000)
                ->helperText('A long-lived user token generated from your Traccar server (Settings → your account → Token, available since Traccar 5.3).'),
        ];
    }
}
