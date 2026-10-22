<?php

namespace App\Support\ChannelTypes;

use App\Models\NotificationChannel;
use App\Support\ChannelTypes\Concerns\IsGuardedChatServer;
use App\Support\ChannelTypes\Concerns\IsMessagingChannel;
use App\Support\ChannelTypes\Contracts\MessagingChannelType;
use App\Support\Delivery\ChatServerAddress;
use InvalidArgumentException;

/**
 * Posts to a Rocket.Chat channel through its incoming webhook on the subscriber's own server,
 * via Apprise's `rocket[s]://` plugin in its webhook mode.
 *
 * The subscriber pastes the whole webhook URL, `https://host[:port]/hooks/{id}/{token}`, the id
 * and token being alphanumeric. The server part is vetted by ChatServerGuard (the field's
 * SafeChatServerUrl rule, again in buildDestination() and before every send), and the Apprise
 * URL's authority is rebuilt from the stored server, so the host Apprise connects to is always
 * the guarded one.
 *
 * Checked against Apprise 2.0.0 (requests monkeypatched, no network):
 * `rockets://{id}%2F{token}@host[:port]?redirect=no&cto=5&rto=10&avatar=no` makes exactly one
 * POST of `{"text": ...}` to `https://host[:port]/hooks/{id}/{token}` (no `api/v1/login` call,
 * redirects off, a (5, 10) s timeout). Apprise percent-decodes the user part, so `%2F` is the
 * `/` between id and token; a raw `/` in the user part gives the same result. A path after the
 * host is not a sub-path: Apprise reads it as a target channel or room (`/sub` posts to channel
 * `sub`, `/%23general` to `#general`), so a sub-path install cannot be supported and a path is
 * never emitted. A body over 1000 characters is sent whole. Apprise decodes what it is given and
 * uses it as is, so `rawurlencode()` does not round-trip for `/ ? # %`: that is why the parser
 * (and appriseUrl()) allow only `[A-Za-z0-9]` in the id and token. That allow-list is the only
 * defence; `rawurlencode()` is a no-op on the allowed values.
 */
class RocketChatChannelType implements MessagingChannelType
{
    use IsGuardedChatServer;
    use IsMessagingChannel;

    public const INVALID_MESSAGE = 'Paste the whole incoming webhook URL from Rocket.Chat; it ends in /hooks/, an ID and a token.';

    public const SUBPATH_MESSAGE = 'Rocket.Chat under a sub-path isn\'t supported yet.';

    public const API_MESSAGE = 'Paste the incoming webhook URL (Administration → Integrations → New → Incoming), not an API address.';

    /** No whitespace or control character anywhere; `D` so `$` never matches before a newline. */
    private const URL_PATTERN = '#^([A-Za-z]+)://([^/?\#@\x00-\x20\x7F]*)(/[^?\#\x00-\x20\x7F]*)$#D';

    private const PATH_PATTERN = '#^/hooks/([A-Za-z0-9]+)/([A-Za-z0-9]+)/?$#D';

    private const SUBPATH_PATTERN = '#^(?:/[A-Za-z0-9._~-]+)+/hooks/[A-Za-z0-9]+/[A-Za-z0-9]+/?$#D';

    public function slug(): string
    {
        return 'rocketchat';
    }

    public function label(): string
    {
        return 'Rocket.Chat';
    }

    public function icon(): string
    {
        return 'fab-rocketchat';
    }

    /**
     * Rocket.Chat's own limit, which Apprise does not split.
     */
    public function maxLength(): int
    {
        return 1000;
    }

    public function formSchema(): array
    {
        return [
            $this->webhookUrlField('In Rocket.Chat: Administration → Integrations → New → Incoming. Paste the whole Webhook URL. The server must be reachable from the internet.'),
        ];
    }

    protected function webhookUrlRefusal(string $url): ?string
    {
        return self::parseWebhookUrl($url) === null ? self::refusalMessage($url) : null;
    }

    /**
     * The normalised server, webhook id and token of a pasted Rocket.Chat incoming webhook URL,
     * or null when it is not one. The server is not vetted here.
     *
     * @return array{server: string, webhook_id: string, webhook_token: string}|null
     */
    public static function parseWebhookUrl(string $url): ?array
    {
        if (preg_match(self::URL_PATTERN, trim($url), $parts) !== 1
            || preg_match(self::PATH_PATTERN, $parts[3], $path) !== 1) {
            return null;
        }

        $server = ChatServerAddress::parts($parts[1].'://'.$parts[2]);

        if ($server === null) {
            return null;
        }

        return [
            'server' => ChatServerAddress::toUrl($server),
            'webhook_id' => $path[1],
            'webhook_token' => $path[2],
        ];
    }

    /**
     * Why a pasted URL is not a Rocket.Chat incoming webhook: an API address and a sub-path
     * install get their own message, everything else the general one.
     */
    public static function refusalMessage(string $url): string
    {
        if (preg_match(self::URL_PATTERN, trim($url), $parts) === 1) {
            if (str_starts_with($parts[3], '/api/')) {
                return self::API_MESSAGE;
            }

            if (preg_match(self::SUBPATH_PATTERN, $parts[3]) === 1) {
                return self::SUBPATH_MESSAGE;
            }
        }

        return self::INVALID_MESSAGE;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{server: string, webhook_id: string, webhook_token: string}
     */
    protected function configFromForm(array $data): array
    {
        $url = (string) ($data['webhook_url'] ?? '');
        $parsed = self::parseWebhookUrl($url);

        if ($parsed === null) {
            throw new InvalidArgumentException(self::refusalMessage($url));
        }

        return $this->guardServer($parsed);
    }

    /**
     * @return list<string>
     */
    public function requiredConfigKeys(): array
    {
        return ['server', 'webhook_id', 'webhook_token'];
    }

    /**
     * `rocket[s]://{id}%2F{token}@{host}[:{port}]?{guarded query}`, the authority rebuilt from
     * the stored server. It is empty (which MessagingTransport refuses) when the server is
     * unusable or the stored id or token is not alphanumeric: Apprise decodes the user part, so
     * a tampered `a/b` would otherwise add a path segment, which it reads as a target channel.
     * There is never a path in the URL.
     *
     * @param  array<string, mixed>  $config
     */
    public function appriseUrl(array $config): string
    {
        $id = (string) ($config['webhook_id'] ?? '');
        $token = (string) ($config['webhook_token'] ?? '');
        $wellFormed = preg_match('/^[A-Za-z0-9]+$/D', $id) === 1 && preg_match('/^[A-Za-z0-9]+$/D', $token) === 1;
        ['scheme' => $scheme, 'authority' => $authority] = $this->chatAuthority($config, 'rockets', 'rocket', $wellFormed);

        return "{$scheme}://".rawurlencode($id).'%2F'.rawurlencode($token)."@{$authority}?".$this->guardedQuery(['avatar' => 'no']);
    }

    /**
     * `host:port/webhook_id`, normalised here because the duplicate check compares the raw
     * string: lower-case host without a trailing dot and the port always written (443 or 80
     * when the URL had none). Empty when the server is unusable.
     *
     * @param  array<string, mixed>  $config
     */
    public function destinationKey(array $config): string
    {
        $server = ChatServerAddress::parts((string) ($config['server'] ?? ''));

        if ($server === null) {
            return '';
        }

        return ChatServerAddress::hostAndPort($server).'/'.(string) ($config['webhook_id'] ?? '');
    }

    public function destinationNoun(): string
    {
        return 'webhook';
    }

    public function destinationLabel(): string
    {
        return 'Rocket.Chat server';
    }

    public function failureMessage(): string
    {
        return 'Rocket.Chat did not accept the message. Check the webhook URL and that the channel still exists.';
    }

    public function confirmationHint(NotificationChannel $channel): string
    {
        return 'We just posted a 6-digit code to your Rocket.Chat channel.';
    }

    public function codeNotSentHint(): string
    {
        return 'We couldn\'t post to that Rocket.Chat webhook. Check the URL and that the server is reachable from the internet.';
    }
}
