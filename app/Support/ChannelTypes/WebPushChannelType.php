<?php

namespace App\Support\ChannelTypes;

use App\Enums\ChannelTransport;
use App\Support\ChannelTypes\Contracts\ChannelType;
use App\Support\Delivery\WebhookUrlGuard;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\ViewField;

class WebPushChannelType implements ChannelType
{
    public function slug(): string
    {
        return 'webpush';
    }

    public function label(): string
    {
        return 'Web Push';
    }

    public function icon(): string
    {
        return 'heroicon-o-computer-desktop';
    }

    public function formSchema(): array
    {
        return [
            Hidden::make('subscription')->required(),
            ViewField::make('web_push_setup')
                ->label('')
                ->view('filament.dashboard.channels.web-push-setup')
                ->dehydrated(false),
        ];
    }

    public function transport(): ChannelTransport
    {
        return ChannelTransport::WebPush;
    }

    /**
     * @return ?array{endpoint: string, public_key: string, auth_token: string, content_encoding: string}
     */
    public static function parseSubscription(string $json): ?array
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            return null;
        }

        $endpoint = $data['endpoint'] ?? null;
        $publicKey = $data['keys']['p256dh'] ?? null;
        $authToken = $data['keys']['auth'] ?? null;

        if (! is_string($endpoint) || ! is_string($publicKey) || ! is_string($authToken) || $publicKey === '' || $authToken === '') {
            return null;
        }

        // A subscriber controls this URL, so it gets the same SSRF check as a webhook URL.
        if (app(WebhookUrlGuard::class)->violation($endpoint) !== null) {
            return null;
        }

        return [
            'endpoint' => $endpoint,
            'public_key' => $publicKey,
            'auth_token' => $authToken,
            'content_encoding' => in_array($data['contentEncoding'] ?? null, ['aesgcm', 'aes128gcm'], true) ? $data['contentEncoding'] : 'aes128gcm',
        ];
    }

    public function buildDestination(array $data): array
    {
        $config = self::parseSubscription((string) ($data['subscription'] ?? ''));

        if ($config === null) {
            throw new \InvalidArgumentException('That browser subscription is not valid.');
        }

        return ['config' => $config, 'label' => 'Web Push ('.self::describeBrowser((string) request()->userAgent()).')'];
    }

    public function countsTowardFreeSlot(): bool
    {
        return true;
    }

    public function supportsInlineCreate(): bool
    {
        return false;
    }

    public function isAvailable(): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }

    private static function describeBrowser(string $userAgent): string
    {
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'unknown OS',
        };

        return "{$browser} on {$os}";
    }
}
