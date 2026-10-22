<?php

namespace App\Support\ChannelTypes;

use App\Models\NotificationChannel;
use App\Support\ChannelTypes\Concerns\IsMessagingChannel;
use App\Support\ChannelTypes\Concerns\MasksDestinationSecrets;
use App\Support\ChannelTypes\Contracts\MessagingChannelType;
use Closure;
use Filament\Forms\Components\TextInput;
use InvalidArgumentException;

/**
 * Posts to a Google Chat space through its incoming webhook, via Apprise's `gchat://` plugin,
 * which posts to the fixed host `chat.googleapis.com` (so there is no server to vet).
 *
 * The subscriber pastes the whole webhook URL,
 * `https://chat.googleapis.com/v1/spaces/{space}/messages?key={key}&token={token}`; only that
 * exact host over https, with no userinfo or port, is accepted. `{space}` and `{key}` are
 * `[A-Za-z0-9_-]`; the token is percent-decoded exactly once (Google shows its `=` padding as
 * `%3D`) and may hold `[A-Za-z0-9._~+=-]`. A `threadKey` or any other query argument is ignored.
 *
 * Checked against Apprise 2.0.0 (no network): `rawurlencode()` of each decoded component
 * round-trips through `gchat://{space}/{key}/{token}` for `=`, `%3D` (sent as `%253D` it would
 * be a different token), `-_`, `@ : ? # % space` and `é`, in every component. A `/` does not:
 * Apprise decodes the path before splitting it, so `%2F` in the key or token cuts it short.
 * That is why the parser refuses a `/` anywhere in them.
 */
class GoogleChatChannelType implements MessagingChannelType
{
    use IsMessagingChannel;
    use MasksDestinationSecrets;

    public const HOST = 'chat.googleapis.com';

    public const INVALID_MESSAGE = 'Paste the whole webhook URL from Google Chat: Apps & integrations → Webhooks.';

    private const PATH_PATTERN = '#^/v1/spaces/([A-Za-z0-9_-]+)/messages$#';

    private const KEY_PATTERN = '/^[A-Za-z0-9_-]+$/';

    private const TOKEN_PATTERN = '/^[A-Za-z0-9._~+=-]+$/';

    public function slug(): string
    {
        return 'google_chat';
    }

    public function label(): string
    {
        return 'Google Chat';
    }

    public function icon(): string
    {
        return 'fab-google';
    }

    public function formSchema(): array
    {
        return [
            TextInput::make('webhook_url')
                ->label('Webhook URL')
                ->password()
                ->revealable()
                ->required()
                ->maxLength(2048)
                ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || self::parseWebhookUrl($value) === null) {
                        $fail(self::INVALID_MESSAGE);
                    }
                }])
                ->helperText('In Google Chat, open the space, then Apps & integrations → Webhooks → Add webhook, and copy the whole URL. Anyone with this URL can post to the space, so we keep it encrypted and never show it again.'),
        ];
    }

    /**
     * The space, key and token of a pasted Google Chat webhook URL, or null when it is not one.
     *
     * @return array{space: string, key: string, token: string}|null
     */
    public static function parseWebhookUrl(string $url): ?array
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== self::HOST
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || preg_match(self::PATH_PATTERN, $parts['path'] ?? '', $path) !== 1) {
            return null;
        }

        $query = [];

        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

            if (in_array($name, ['key', 'token'], true)) {
                if (array_key_exists($name, $query)) {
                    return null;
                }

                $query[$name] = rawurldecode($value);
            }
        }

        $key = $query['key'] ?? '';
        $token = $query['token'] ?? '';

        if (preg_match(self::KEY_PATTERN, $key) !== 1 || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        return ['space' => $path[1], 'key' => $key, 'token' => $token];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{space: string, key: string, token: string}
     */
    protected function configFromForm(array $data): array
    {
        $parsed = self::parseWebhookUrl((string) ($data['webhook_url'] ?? ''));

        if ($parsed === null) {
            throw new InvalidArgumentException(self::INVALID_MESSAGE);
        }

        return $parsed;
    }

    /**
     * @return list<string>
     */
    public function requiredConfigKeys(): array
    {
        return ['space', 'key', 'token'];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function appriseUrl(array $config): string
    {
        $space = rawurlencode((string) ($config['space'] ?? ''));
        $key = rawurlencode((string) ($config['key'] ?? ''));
        $token = rawurlencode((string) ($config['token'] ?? ''));

        return "gchat://{$space}/{$key}/{$token}";
    }

    /**
     * The host is always chat.googleapis.com.
     *
     * @param  array<string, mixed>  $config
     */
    public function serverUrl(array $config): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function destinationKey(array $config): string
    {
        return (string) ($config['space'] ?? '');
    }

    public function displaySummary(NotificationChannel $record): string
    {
        $space = $record->config['space'] ?? null;

        return is_string($space) && $space !== '' ? 'spaces/'.self::maskLast4($space) : '—';
    }

    public function destinationLabel(): string
    {
        return 'Google Chat space';
    }

    public function failureMessage(): string
    {
        return 'Google Chat did not accept the message. Check the webhook URL and that the webhook still exists in the space.';
    }

    public function confirmationHint(NotificationChannel $channel): string
    {
        return 'We just posted a 6-digit code to your Google Chat space.';
    }

    public function codeNotSentHint(): string
    {
        return 'We couldn\'t post to that Google Chat webhook. Check the URL, and that the webhook still exists in the space.';
    }
}
