<?php

namespace App\Support\Delivery;

use App\Enums\ChannelTransport;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\Delivery\Transports\AppriseTransport;
use App\Support\Delivery\Transports\Transport;
use App\Support\Delivery\Transports\WebhookTransport;
use App\Support\Delivery\Transports\WebPushTransport;

class ChannelDispatcher
{
    public function __construct(private readonly ChannelTypeRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function send(NotificationChannel $channel, string $body, ?string $title = null, array $meta = []): DeliveryResult
    {
        $type = $this->registry->find($channel->channel_type);

        if ($type === null) {
            return DeliveryResult::failed("Unknown channel type [{$channel->channel_type}].");
        }

        return $this->transportFor($type->transport())->send($channel, $body, $title, $meta);
    }

    private function transportFor(ChannelTransport $transport): Transport
    {
        return match ($transport) {
            ChannelTransport::Apprise => app(AppriseTransport::class),
            ChannelTransport::Webhook => app(WebhookTransport::class),
            ChannelTransport::WebPush => app(WebPushTransport::class),
        };
    }
}
