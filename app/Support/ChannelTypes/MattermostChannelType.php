<?php

namespace App\Support\ChannelTypes;

use App\Models\NotificationChannel;
use App\Support\ChannelTypes\Concerns\IsGuardedChatServer;
use App\Support\ChannelTypes\Concerns\IsMessagingChannel;
use App\Support\ChannelTypes\Contracts\MessagingChannelType;
use App\Support\Delivery\ChatServerAddress;
use InvalidArgumentException;

/**
 * Posts to a Mattermost channel through its incoming webhook on the subscriber's own server,
 * via Apprise's `mmost[s]://` plugin in its default webhook mode.
 *
 * The subscriber pastes the whole webhook URL, `https://host[:port][/sub/path]/hooks/{id}`. The
 * server part is vetted by ChatServerGuard (the field's SafeChatServerUrl rule, again in
 * buildDestination() and before every send); the path is this type's own rule: sub-path
 * segments of `[A-Za-z0-9._~-]` (not `.` or `..`), then `/hooks/` and Mattermost's 26 lower-case
 * alphanumerics, with one trailing slash trimmed. The stored `server` is ChatServerAddress's
 * spelling, and the Apprise URL's authority is rebuilt from it, so the host Apprise connects to
 * is always the guarded one.
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
    use IsGuardedChatServer;
    use IsMessagingChannel;

    public const INVALID_MESSAGE = 'Paste the whole incoming webhook URL from Mattermost; it ends in /hooks/ and a 26-character ID.';

    /** No whitespace or control character anywhere; `D` so `$` never matches before a newline. */
    private const URL_PATTERN = '#^([A-Za-z]+)://([^/?\#@\x00-\x20\x7F]*)(/[^?\#\x00-\x20\x7F]*)$#D';

    private const PATH_PATTERN = '#^((?:/[A-Za-z0-9._~-]+)*)/hooks/([a-z0-9]{26})/?$#D';

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
            $this->webhookUrlField('In Mattermost: Integrations → Incoming Webhooks → Add. Paste the whole URL. The server must be reachable from the internet.'),
        ];
    }

    protected function webhookUrlRefusal(string $url): ?string
    {
        return self::parseWebhookUrl($url) === null ? self::INVALID_MESSAGE : null;
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

        $server = ChatServerAddress::parts($parts[1].'://'.$parts[2]);

        if ($server === null) {
            return null;
        }

        return [
            'server' => ChatServerAddress::toUrl($server),
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

        return $this->guardServer($parsed);
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
     * from the stored server. It is empty (which MessagingTransport refuses) when the server is
     * unusable or the stored path or token is outside what the parser allows: Apprise decodes
     * the path, so a tampered `a/../b` or a `?` would change where it posts.
     *
     * @param  array<string, mixed>  $config
     */
    public function appriseUrl(array $config): string
    {
        $segments = self::segments((string) ($config['path'] ?? ''));
        $token = (string) ($config['token'] ?? '');
        $wellFormed = preg_match('/^[a-z0-9]{26}$/D', $token) === 1;

        foreach ($segments as $segment) {
            $wellFormed = $wellFormed && preg_match('/^[A-Za-z0-9._~-]+$/D', $segment) === 1 && trim($segment, '.') !== '';
        }

        ['scheme' => $scheme, 'authority' => $authority] = $this->chatAuthority($config, 'mmosts', 'mmost', $wellFormed);
        $path = implode('', array_map(fn (string $segment): string => '/'.rawurlencode($segment), $segments));

        return "{$scheme}://{$authority}{$path}/".rawurlencode($token).'?'.$this->guardedQuery(['image' => 'no']);
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
        $server = ChatServerAddress::parts((string) ($config['server'] ?? ''));

        if ($server === null) {
            return '';
        }

        $segments = [...self::segments((string) ($config['path'] ?? '')), (string) ($config['token'] ?? '')];

        return ChatServerAddress::hostAndPort($server).'/'.implode('/', $segments);
    }

    public function destinationNoun(): string
    {
        return 'webhook';
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
     * The non-empty segments of a stored sub-path.
     *
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('/', $path), fn (string $segment): bool => $segment !== ''));
    }
}
