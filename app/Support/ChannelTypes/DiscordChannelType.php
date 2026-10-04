<?php

namespace App\Support\ChannelTypes;

use App\Models\NotificationChannel;
use App\Support\ChannelTypes\Concerns\MasksDestinationSecrets;
use App\Support\ChannelTypes\Concerns\UsesAppriseTransport;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Filament\Forms\Components\TextInput;

class DiscordChannelType implements ChannelType
{
    use MasksDestinationSecrets, UsesAppriseTransport;

    public function slug(): string
    {
        return 'discord';
    }

    public function label(): string
    {
        return 'Discord';
    }

    public function icon(): string
    {
        return 'fab-discord';
    }

    public function formSchema(): array
    {
        return [
            TextInput::make('webhook_url')
                ->label('Webhook URL')
                ->url()
                ->required()
                ->helperText('From your Discord channel: Edit Channel → Integrations → Webhooks.')
                ->rule('regex:#^https://discord(?:app)?\.com/api/webhooks/\d+/[\w-]+/?$#'),
        ];
    }

    public function buildAppriseUrl(array $data): string
    {
        preg_match('#/webhooks/(\d+)/([\w-]+)#', $data['webhook_url'], $matches);

        return "discord://{$matches[1]}/{$matches[2]}/";
    }

    public function supportsRichContent(): bool
    {
        return false;
    }

    public function countsTowardFreeSlot(): bool
    {
        return true;
    }

    public function displaySummary(NotificationChannel $record): string
    {
        if (! preg_match('#^discord://\d+/([\w-]+)/?$#', (string) $record->apprise_url, $matches)) {
            return '—';
        }

        return self::maskLast4($matches[1]);
    }

    public function destinationLabel(): string
    {
        return 'Discord webhook';
    }
}
