<?php

namespace App\Support\Delivery\Transports;

use App\Models\NotificationChannel;
use App\Support\Apprise\AppriseApiClient;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\MessagingChannelType;
use App\Support\Delivery\ChatServerGuard;
use App\Support\Delivery\DeliveryResult;

/**
 * Posts to a messaging channel through Apprise with a URL built from the channel's encrypted
 * `config` at send time: the URL holds the webhook secret or token, so it is never stored,
 * logged or put in an error. The text is the type's own composition, sent with no title (the
 * title is in the text). Failures are the type's fixed text. A ConnectionException propagates,
 * as from SmsTransport.
 */
class MessagingTransport implements Transport
{
    public const SERVER_MISMATCH = "This channel's server address does not match its settings.";

    public function __construct(
        private readonly AppriseApiClient $client,
        private readonly ChannelTypeRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function send(NotificationChannel $channel, string $body, ?string $title, array $meta = []): DeliveryResult
    {
        $type = $this->registry->find($channel->channel_type);

        if (! $type instanceof MessagingChannelType) {
            return DeliveryResult::failed('This channel cannot send messages.');
        }

        $config = $channel->config;

        if (! is_array($config) || ! $this->hasSettings($type, $config)) {
            return DeliveryResult::failed('This channel is missing its settings.');
        }

        $refusal = $this->serverRefusal($type, $config);

        if ($refusal !== null) {
            return DeliveryResult::failed($refusal);
        }

        $url = $type->appriseUrlFor($config, $meta);

        // A type gives an empty URL when a stored part is outside its allow-list (a tampered value).
        if ($url === '') {
            return DeliveryResult::failed('This channel is missing its settings.');
        }

        return $this->client->notify(url: $url, body: $type->compose($body, $title, $meta), title: null)
            ? DeliveryResult::delivered()
            : DeliveryResult::failed($type->failureMessage());
    }

    /**
     * Why the subscriber-supplied server must not be posted to, checked before any HTTP and
     * again on every send, because the name may resolve elsewhere now than when it was saved. A
     * type with a fixed host (`serverUrl()` null, Google Chat) is never refused here. The refusal
     * is ChatServerGuard's fixed message, which never repeats the address.
     *
     * @param  array<string, mixed>  $config
     */
    private function serverRefusal(MessagingChannelType $type, array $config): ?string
    {
        $serverUrl = $type->serverUrl($config);

        if ($serverUrl === null) {
            return null;
        }

        $violation = app(ChatServerGuard::class)->violation($serverUrl);

        if ($violation !== null) {
            return $violation;
        }

        $guardedHost = $this->comparableHost((string) parse_url($serverUrl, PHP_URL_HOST));
        $appriseHost = $this->appriseHost($type->appriseUrl($config));

        return $appriseHost !== null && $appriseHost === $guardedHost ? null : self::SERVER_MISMATCH;
    }

    /**
     * The host an Apprise URL connects to (`mmosts://host/...`, `rockets://id%2Ftoken@host`,
     * `matrixs://token@[v6]/...`): the authority after the last `@` (userinfo is rawurlencoded,
     * so it holds no raw `@` of its own), without the port. Null when there is none, or when it
     * is percent-encoded and so could name a different host once Apprise decodes it.
     */
    private function appriseHost(string $appriseUrl): ?string
    {
        if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://([^/?\#]*)#', $appriseUrl, $matches) !== 1) {
            return null;
        }

        $authority = $matches[1];
        $at = strrpos($authority, '@');
        $hostPort = $at === false ? $authority : substr($authority, $at + 1);
        $host = preg_match('/^(\[[^\]]*\]|[^:]*)/', $hostPort, $hostMatch) === 1 ? $hostMatch[1] : '';

        if ($host === '' || str_contains($host, '%')) {
            return null;
        }

        return $this->comparableHost($host);
    }

    /** Lower case, brackets and one trailing dot dropped, an IP literal in one spelling. */
    private function comparableHost(string $host): string
    {
        $host = strtolower(trim($host, '[]'));
        $host = str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
        $packed = filter_var($host, FILTER_VALIDATE_IP) !== false ? inet_pton($host) : false;

        return $packed === false ? $host : (string) inet_ntop($packed);
    }

    /**
     * Every key the type requires is present and not blank.
     *
     * @param  array<string, mixed>  $config
     */
    private function hasSettings(MessagingChannelType $type, array $config): bool
    {
        foreach ($type->requiredConfigKeys() as $key) {
            if (blank($config[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
