<?php

namespace App\Support\Delivery;

use App\Enums\ChannelTransport;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\ByoChannelType;
use App\Support\ChannelTypes\Contracts\ChannelType;
use App\Support\ChannelTypes\Contracts\PhoneChannelType;
use App\Support\Delivery\Transports\AppriseTransport;
use App\Support\Delivery\Transports\MailTransport;
use App\Support\Delivery\Transports\MessagingTransport;
use App\Support\Delivery\Transports\MobilePushTransport;
use App\Support\Delivery\Transports\SmsTransport;
use App\Support\Delivery\Transports\Transport;
use App\Support\Delivery\Transports\WebhookTransport;
use App\Support\Delivery\Transports\WebPushTransport;
use App\Support\MessagePrefix;

class ChannelDispatcher
{
    /**
     * Why a channel whose owner is below the type's catalog `min_tier` sends nothing.
     */
    public const TIER_REFUSAL = 'This channel needs the Plus plan or higher.';

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

        $refusal = $this->refusal($channel, $type, $meta);

        if ($refusal !== null) {
            return DeliveryResult::failed($refusal);
        }

        $title = $title !== null ? MessagePrefix::apply($title) : null;

        return $this->transportFor($type->transport())->send($channel, $body, $title, $meta);
    }

    /**
     * Why a send must not go out, checked before any transport or HTTP call: an owner below
     * the type's catalog `min_tier` (a downgraded subscriber's channel is kept and refused,
     * and works again on re-upgrade), or an unverified bring-your-own channel (phone or messaging)
     * getting anything but its confirmation code. The code is exempt from the second check only,
     * never the first.
     *
     * @param  array<string, mixed>  $meta
     */
    private function refusal(NotificationChannel $channel, ChannelType $type, array $meta): ?string
    {
        if ($channel->user !== null && ! $channel->user->meetsChannelTier($type->slug())) {
            return self::TIER_REFUSAL;
        }

        if ($type instanceof ByoChannelType && ! $channel->isVerified() && ($meta['type'] ?? null) !== 'confirmation') {
            return $type instanceof PhoneChannelType
                ? 'This number has not been confirmed yet.'
                : 'This channel has not been confirmed yet.';
        }

        return null;
    }

    private function transportFor(ChannelTransport $transport): Transport
    {
        return match ($transport) {
            ChannelTransport::Apprise => app(AppriseTransport::class),
            ChannelTransport::Webhook => app(WebhookTransport::class),
            ChannelTransport::WebPush => app(WebPushTransport::class),
            ChannelTransport::Mail => app(MailTransport::class),
            ChannelTransport::MobilePush => app(MobilePushTransport::class),
            ChannelTransport::Sms => app(SmsTransport::class),
            ChannelTransport::Messaging => app(MessagingTransport::class),
        };
    }
}
