<?php

namespace App\Support\Delivery\Transports;

use App\Models\NotificationChannel;
use App\Support\Apprise\AppriseApiClient;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\SmsChannelType;
use App\Support\Delivery\DeliveryResult;

/**
 * Texts through Apprise with a URL built from the channel's encrypted `config` at send time:
 * the URL holds the provider credentials, so it is never stored, logged or put in an error.
 * Failures are the type's fixed text. A ConnectionException propagates, as from AppriseTransport.
 */
class SmsTransport implements Transport
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

        if (! $type instanceof SmsChannelType) {
            return DeliveryResult::failed('This channel cannot send text messages.');
        }

        $config = $channel->config;

        if (! is_array($config) || ! $this->hasSettings($config)) {
            return DeliveryResult::failed('This SMS channel is missing its settings.');
        }

        return $this->client->notify(url: $type->appriseUrl($config), body: $body, title: $title)
            ? DeliveryResult::delivered()
            : DeliveryResult::failed($type->failureMessage());
    }

    /**
     * Both numbers are present and no stored setting is blank.
     *
     * @param  array<string, mixed>  $config
     */
    private function hasSettings(array $config): bool
    {
        if (blank($config['from'] ?? null) || blank($config['to'] ?? null)) {
            return false;
        }

        foreach ($config as $value) {
            if (blank($value)) {
                return false;
            }
        }

        return true;
    }
}
