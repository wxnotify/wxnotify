<?php

namespace App\Support\ChannelTypes;

use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelTypes\Concerns\MasksDestinationSecrets;
use App\Support\ChannelTypes\Concerns\UsesAppriseTransport;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Filament\Forms\Components\TextInput;

class SlackChannelType implements ChannelType
{
    use MasksDestinationSecrets, UsesAppriseTransport;

    public const WEBHOOK_URL_PATTERN = '#^https://hooks\.slack\.com/services/([\w-]+)/([\w-]+)/([\w-]+)/?$#';

    public function slug(): string
    {
        return 'slack';
    }

    public function label(): string
    {
        return 'Slack';
    }

    public function icon(): string
    {
        return 'fab-slack';
    }

    public function formSchema(): array
    {
        return [
            TextInput::make('webhook_url')
                ->label('Incoming Webhook URL')
                ->url()
                ->required()
                ->helperText('From Slack: Apps → Incoming Webhooks → Add New Webhook to Workspace.')
                ->rule('regex:'.self::WEBHOOK_URL_PATTERN),
        ];
    }

    public function appSetupFields(User $user): ?array
    {
        return [
            new ChannelSetupField('webhook_url', 'Incoming Webhook URL', ChannelSetupField::SECRET, help: 'From Slack: Apps → Incoming Webhooks → Add New Webhook to Workspace.', rules: ['url', 'max:2048', 'regex:'.self::WEBHOOK_URL_PATTERN]),
        ];
    }

    public function buildAppriseUrl(array $data): string
    {
        preg_match('#/services/([\w-]+)/([\w-]+)/([\w-]+)#', $data['webhook_url'], $matches);

        return "slack://{$matches[1]}/{$matches[2]}/{$matches[3]}/";
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
        if (! preg_match('#^slack://([\w-]+)/([\w-]+)/([\w-]+)/?$#', (string) $record->apprise_url, $matches)) {
            return '—';
        }

        return self::maskLast4($matches[3]);
    }

    public function destinationLabel(): string
    {
        return 'Slack webhook';
    }
}
