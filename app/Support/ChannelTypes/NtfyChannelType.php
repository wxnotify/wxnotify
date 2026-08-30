<?php

namespace App\Support\ChannelTypes;

use App\Support\ChannelTypes\Contracts\ChannelType;
use Filament\Forms\Components\TextInput;

class NtfyChannelType implements ChannelType
{
    public function slug(): string
    {
        return 'ntfy';
    }

    public function label(): string
    {
        return 'ntfy';
    }

    public function icon(): string
    {
        return 'heroicon-o-bell-alert';
    }

    public function formSchema(): array
    {
        return [
            TextInput::make('topic')
                ->label('Topic')
                ->required()
                ->alphaDash()
                ->helperText('A short, hard-to-guess name — anyone who knows it can subscribe. E.g. "wxnotify-a1b2c3".'),
            TextInput::make('server')
                ->label('Server (optional)')
                ->url()
                ->helperText('Leave blank to use the public ntfy.sh server, or enter your own self-hosted server URL.'),
        ];
    }

    public function buildAppriseUrl(array $data): string
    {
        $topic = rawurlencode($data['topic']);

        if (filled($data['server'] ?? null)) {
            $host = preg_replace('#^https?://#', '', rtrim($data['server'], '/'));

            return str_starts_with($data['server'], 'https://')
                ? "ntfys://{$host}/{$topic}"
                : "ntfy://{$host}/{$topic}";
        }

        return "ntfy://{$topic}";
    }

    public function countsTowardFreeSlot(): bool
    {
        return true;
    }
}
