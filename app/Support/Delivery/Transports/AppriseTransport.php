<?php

namespace App\Support\Delivery\Transports;

use App\Models\NotificationChannel;
use App\Support\Apprise\AppriseApiClient;
use App\Support\ChannelTypes\NtfyChannelType;
use App\Support\Delivery\ChatServerGuard;
use App\Support\Delivery\DeliveryResult;

class AppriseTransport implements Transport
{
    public function __construct(private readonly AppriseApiClient $client) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function send(NotificationChannel $channel, string $body, ?string $title, array $meta = []): DeliveryResult
    {
        // A subscriber-chosen ntfy server is vetted again on every send: the name may resolve
        // elsewhere now than when it was saved. The refusal is fixed text and never repeats the URL.
        $ntfyServer = NtfyChannelType::serverUrlFrom((string) $channel->apprise_url);

        if ($ntfyServer !== null && app(ChatServerGuard::class)->violation($ntfyServer) !== null) {
            return DeliveryResult::failed(NtfyChannelType::SERVER_REFUSED);
        }

        return $this->client->notify(url: (string) $channel->apprise_url, body: $body, title: $title)
            ? DeliveryResult::delivered()
            : DeliveryResult::failed('Apprise reported non-delivery.');
    }
}
