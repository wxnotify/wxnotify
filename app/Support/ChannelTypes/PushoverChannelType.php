<?php

namespace App\Support\ChannelTypes;

use App\Support\ChannelTypes\Concerns\UsesAppriseTransport;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Filament\Forms\Components\TextInput;

class PushoverChannelType implements ChannelType
{
    use UsesAppriseTransport;

    public function slug(): string
    {
        return 'pushover';
    }

    public function label(): string
    {
        return 'Pushover';
    }

    public function icon(): string
    {
        return 'heroicon-o-device-phone-mobile';
    }

    public function formSchema(): array
    {
        return [
            TextInput::make('user_key')
                ->label('User key')
                ->required()
                ->regex('/^[A-Za-z0-9]{30}$/')
                ->validationMessages(['regex' => 'A Pushover user key is 30 letters and numbers.'])
                ->helperText('Shown at the top of your Pushover dashboard (pushover.net).'),
            TextInput::make('api_token')
                ->label('Application token')
                ->required()
                ->password()
                ->revealable()
                ->regex('/^[A-Za-z0-9]{30}$/')
                ->validationMessages(['regex' => 'A Pushover application token is 30 letters and numbers.'])
                ->helperText('Create an application at pushover.net/apps and copy its API token.'),
        ];
    }

    public function buildAppriseUrl(array $data): string
    {
        return "pover://{$data['user_key']}@{$data['api_token']}";
    }

    public function countsTowardFreeSlot(): bool
    {
        return true;
    }
}
