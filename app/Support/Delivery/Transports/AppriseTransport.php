<?php

namespace App\Support\Delivery\Transports;

use App\Models\NotificationChannel;
use App\Support\Apprise\AppriseApiClient;
use App\Support\Delivery\DeliveryResult;

class AppriseTransport implements Transport
{
    public function __construct(private readonly AppriseApiClient $client) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function send(NotificationChannel $channel, string $body, ?string $title, array $meta = []): DeliveryResult
    {
        return $this->client->notify(url: (string) $channel->apprise_url, body: $body, title: $title)
            ? DeliveryResult::delivered()
            : DeliveryResult::failed('Apprise reported non-delivery.');
    }
}
