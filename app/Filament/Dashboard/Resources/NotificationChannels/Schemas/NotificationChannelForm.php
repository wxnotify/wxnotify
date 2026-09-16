<?php

namespace App\Filament\Dashboard\Resources\NotificationChannels\Schemas;

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
            ]);
    }
}
