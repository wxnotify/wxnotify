<?php

namespace App\Support\ChannelTypes;

use App\Models\NotificationChannel;
use App\Rules\SafeChatServerUrl;
use App\Support\ChannelTypes\Concerns\IsMessagingChannel;
use App\Support\ChannelTypes\Contracts\MessagingChannelType;
use App\Support\Delivery\ChatServerGuard;
use Closure;
use Filament\Forms\Components\TextInput;
use InvalidArgumentException;

/**
 * Posts to a Mattermost channel through its incoming webhook on the subscriber's own server,
 * via Apprise's `mmost[s]://` plugin in its default webhook mode.
 *
 * The subscriber pastes the whole webhook URL, `https://host[:port][/sub/path]/hooks/{id}`. The
 * server part is vetted by ChatServerGuard (the field's SafeChatServerUrl rule, again in
 * buildDestination() and before every send); the path is this type's own rule: sub-path
 * segments of `[A-Za-z0-9._~-]` (not `.` or `..`), then `/hooks/` and Mattermost's 26 lower-case
 * alphanumerics, with one trailing slash trimmed. The stored `server` is normalised
 * (`scheme://host[:port]`: lower case, one trailing dot and the default port dropped, an IPv6
 * literal in one spelling), and the Apprise URL's authority is rebuilt from it, so the host
 * Apprise connects to is always the guarded one.
 *
 * Checked against Apprise 2.0.0 (requests monkeypatched, no network):
 * `mmosts://host[:port]/{segments}/{token}?redirect=no&cto=5&rto=10&image=no` makes exactly one
 * POST to `https://host[:port]/{segments}/hooks/{token}` with redirects off and a (5, 10) s
 * timeout, also on a 500 (no retry). Apprise percent-decodes the path before it splits it and
 * puts the decoded characters into the request URL as they are, so `%2F` becomes a separator
 * and `%3F`/`%23` a query or fragment. That is why the parser refuses every character outside
 * the segment and token sets rather than relying on `rawurlencode()`, which stays as a second
 * line of defence.
 */
class MattermostChannelType implements MessagingChannelType
{
    use IsMessagingChannel;

    public const INVALID_MESSAGE = 'Paste the whole incoming webhook URL from Mattermost; it ends in /hooks/ and a 26-character ID.';

    private const URL_PATTERN = '#^([A-Za-z]+)://([^/?\#@]*)(/[^?\#]*)$#';

    private const PATH_PATTERN = '#^((?:/[A-Za-z0-9._~-]+)*)/hooks/([a-z0-9]{26})/?$#';

    private const SERVER_PATTERN = '#^(https?)://(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::(\d{1,5}))?/?$#';

    public function slug(): string
    {
        return 'mattermost';
    }

    public function label(): string
    {
        return 'Mattermost';
    }

    public function icon(): string
    {
        return 'fab-mattermost';
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
                ->rules([
                    fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (! is_string($value) || self::parseWebhookUrl($value) === null) {
                            $fail(self::INVALID_MESSAGE);
                        }
                    },
                    // The guard judges the URL as pasted, less surrounding whitespace (its path is the rule above's).
                    fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        (new SafeChatServerUrl)->validate($attribute, is_string($value) ? trim($value) : $value, $fail);
                    },
                ])
                ->helperText('In Mattermost: Integrations → Incoming Webhooks → Add. Paste the whole URL. The server must be reachable from the internet.'),
        ];
    }

    /**
     * The normalised server, the sub-path and the token of a pasted Mattermost incoming
     * webhook URL, or null when it is not one. The server is not vetted here.
     *
     * @return array{server: string, path: string, token: string}|null
     */
    public static function parseWebhookUrl(string $url): ?array
    {
        if (preg_match(self::URL_PATTERN, trim($url), $parts) !== 1
            || preg_match(self::PATH_PATTERN, $parts[3], $path) !== 1) {
            return null;
        }

        $segments = self::segments($path[1]);

        foreach ($segments as $segment) {
            if (trim($segment, '.') === '') {
                return null;
            }
        }

        $server = self::serverParts($parts[1].'://'.$parts[2]);

        if ($server === null) {
            return null;
        }

        return [
            'server' => self::serverString($server),
            'path' => $segments === [] ? '' : '/'.implode('/', $segments),
            'token' => $path[2],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{server: string, path: string, token: string}
     */
    protected function configFromForm(array $data): array
    {
        $parsed = self::parseWebhookUrl((string) ($data['webhook_url'] ?? ''));

        if ($parsed === null) {
            throw new InvalidArgumentException(self::INVALID_MESSAGE);
        }

        $violation = app(ChatServerGuard::class)->violation($parsed['server']);

        if ($violation !== null) {
            throw new InvalidArgumentException($violation);
        }

        return $parsed;
    }

    /**
     * The path may be empty (a server without a sub-path).
     *
     * @return list<string>
     */
    public function requiredConfigKeys(): array
    {
        return ['server', 'token'];
    }

    /**
     * `mmost[s]://{host}[:{port}]{/segment...}/{token}?{guarded query}`, the authority rebuilt
     * from the stored server (empty when it is unusable, which MessagingTransport refuses).
     *
     * @param  array<string, mixed>  $config
     */
    public function appriseUrl(array $config): string
    {
        $server = self::serverParts((string) ($config['server'] ?? ''));
        $scheme = ($server['scheme'] ?? 'https') === 'https' ? 'mmosts' : 'mmost';
        $authority = $server === null ? '' : $server['host'].($server['port'] === null ? '' : ':'.$server['port']);
        $path = implode('', array_map(
            fn (string $segment): string => '/'.rawurlencode($segment),
            self::segments((string) ($config['path'] ?? '')),
        ));
        $token = rawurlencode((string) ($config['token'] ?? ''));

        return "{$scheme}://{$authority}{$path}/{$token}?".$this->guardedQuery(['image' => 'no']);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function serverUrl(array $config): ?string
    {
        return (string) ($config['server'] ?? '');
    }

    /**
     * `host:port/path/token`, normalised here because the duplicate check compares the raw
     * string: lower-case host without a trailing dot, the port always written (443 or 80 when
     * the URL had none), and the path's segments without empty ones or a trailing slash.
     * Empty when the server is unusable.
     *
     * @param  array<string, mixed>  $config
     */
    public function destinationKey(array $config): string
    {
        $server = self::serverParts((string) ($config['server'] ?? ''));

        if ($server === null) {
            return '';
        }

        $port = $server['port'] ?? ($server['scheme'] === 'https' ? 443 : 80);
        $segments = [...self::segments((string) ($config['path'] ?? '')), (string) ($config['token'] ?? '')];

        return "{$server['host']}:{$port}/".implode('/', $segments);
    }

    public function destinationNoun(): string
    {
        return 'channel';
    }

    /**
     * The server's host, which is not a secret (the token is).
     */
    public function displaySummary(NotificationChannel $record): string
    {
        $server = self::serverParts((string) ($record->config['server'] ?? ''));

        return $server['host'] ?? '—';
    }

    public function destinationLabel(): string
    {
        return 'Mattermost server';
    }

    public function failureMessage(): string
    {
        return 'Mattermost did not accept the message. Check the webhook URL and that the channel still exists.';
    }

    public function confirmationHint(NotificationChannel $channel): string
    {
        return 'We just posted a 6-digit code to your Mattermost channel.';
    }

    public function codeNotSentHint(): string
    {
        return 'We couldn\'t post to that Mattermost webhook. Check the URL and that the server is reachable from the internet.';
    }

    /**
     * The scheme, host and port of `scheme://host[:port]` in one spelling: lower case, one
     * trailing dot dropped, an IPv6 literal canonicalised (brackets kept) and the scheme's
     * default port dropped. Null when it is not such an address.
     *
     * @return array{scheme: string, host: string, port: ?int}|null
     */
    private static function serverParts(string $server): ?array
    {
        if (preg_match(self::SERVER_PATTERN, strtolower($server), $parts) !== 1) {
            return null;
        }

        $host = $parts[2];

        if (str_starts_with($host, '[')) {
            $packed = @inet_pton(substr($host, 1, -1));

            if ($packed === false) {
                return null;
            }

            $host = '['.inet_ntop($packed).']';
        } elseif (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        $port = isset($parts[3]) ? (int) $parts[3] : null;

        if ($host === '' || $port === ($parts[1] === 'https' ? 443 : 80)) {
            $port = null;
        }

        return $host === '' ? null : ['scheme' => $parts[1], 'host' => $host, 'port' => $port];
    }

    /**
     * @param  array{scheme: string, host: string, port: ?int}  $server
     */
    private static function serverString(array $server): string
    {
        return "{$server['scheme']}://{$server['host']}".($server['port'] === null ? '' : ':'.$server['port']);
    }

    /**
     * The non-empty segments of a stored sub-path.
     *
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('/', $path), fn (string $segment): bool => $segment !== ''));
    }
}
