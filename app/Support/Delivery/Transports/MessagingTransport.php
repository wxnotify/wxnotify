<?php

namespace App\Support\Delivery\Transports;

use App\Models\NotificationChannel;
use App\Support\Apprise\AppriseApiClient;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\MessagingChannelType;
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

        return $this->client->notify(url: $type->appriseUrl($config), body: $type->compose($body, $title, $meta), title: null)
            ? DeliveryResult::delivered()
            : DeliveryResult::failed($type->failureMessage());
    }

    /**
     * Why the subscriber-supplied server must not be posted to, checked before any HTTP. A type
     * with a fixed host (`serverUrl()` null, Google Chat) is never refused here. Until the send-time
     * server check exists (ChatServerGuard), a type that names its own server fails closed, so no
     * subscriber host is ever reached unvetted.
     *
     * @param  array<string, mixed>  $config
     */
    private function serverRefusal(MessagingChannelType $type, array $config): ?string
    {
        if ($type->serverUrl($config) === null) {
            return null;
        }

        return 'This channel\'s server could not be checked.';
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
