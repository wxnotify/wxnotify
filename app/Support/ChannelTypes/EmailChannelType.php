<?php

namespace App\Support\ChannelTypes;

use App\Enums\ChannelTransport;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Filament\Forms\Components\TextInput;

class EmailChannelType implements ChannelType
{
    public function slug(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'Email';
    }

    public function icon(): string
    {
        return 'heroicon-o-envelope';
    }

    public function formSchema(): array
    {
        return [
            TextInput::make('to')
                ->label('Email address')
                ->email()
                ->required()
                ->default(fn () => auth()->user()->email),
        ];
    }

    public function transport(): ChannelTransport
    {
        return ChannelTransport::Mail;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{config: array{to: string}}
     */
    public function buildDestination(array $data): array
    {
        return ['config' => ['to' => (string) $data['to']]];
    }

    public function supportsInlineCreate(): bool
    {
        return true;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function countsTowardFreeSlot(): bool
    {
        return false;
    }
}
