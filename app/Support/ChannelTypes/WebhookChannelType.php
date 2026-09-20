<?php

namespace App\Support\ChannelTypes;

use App\Enums\ChannelTransport;
use App\Rules\SafeWebhookUrl;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;

class WebhookChannelType implements ChannelType
{
    public function slug(): string
    {
        return 'webhook';
    }

    public function label(): string
    {
        return 'Custom Webhook';
    }

    public function icon(): string
    {
        return 'heroicon-o-arrow-up-right';
    }

    public function formSchema(): array
    {
        return [
            TextInput::make('url')
                ->label('Webhook URL')
                ->url()
                ->required()
                ->rule(new SafeWebhookUrl)
                ->helperText('WxNotify will POST a signed JSON payload to this https:// URL. You will get a signing secret on the next step.'),
        ];
    }

    public function transport(): ChannelTransport
    {
        return ChannelTransport::Webhook;
    }

    public function buildDestination(array $data): array
    {
        return [
            'config' => ['url' => $data['url'], 'secret' => bin2hex(random_bytes(32))],
            'label' => 'Webhook ('.Str::limit((string) parse_url($data['url'], PHP_URL_HOST), 40, '').')',
        ];
    }

    public function countsTowardFreeSlot(): bool
    {
        return true;
    }

    /**
     * False: the wizard's confirm step is the only place the signing secret
     * is shown at creation time, and the alert-rule form's inline create has
     * no such step.
     */
    public function supportsInlineCreate(): bool
    {
        return false;
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
