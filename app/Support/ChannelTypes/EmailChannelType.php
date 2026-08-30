<?php

namespace App\Support\ChannelTypes;

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

    public function buildAppriseUrl(array $data): string
    {
        $host = config('mail.mailers.smtp.host', 'localhost');
        $port = config('mail.mailers.smtp.port', 587);
        $username = config('mail.mailers.smtp.username');
        $password = config('mail.mailers.smtp.password');
        $from = config('services.apprise.from_email');

        $mode = match (config('mail.mailers.smtp.encryption')) {
            'tls', 'starttls' => 'starttls',
            'ssl' => 'ssl',
            default => 'insecure',
        };

        $auth = $username ? rawurlencode($username).':'.rawurlencode((string) $password).'@' : '';

        return "mailto://{$auth}{$host}:{$port}/?to=".rawurlencode($data['to'])
            .'&from='.rawurlencode($from)
            ."&mode={$mode}";
    }

    public function countsTowardFreeSlot(): bool
    {
        return false;
    }
}
