<?php

namespace App\Support\Delivery;

use App\Enums\ChannelTransport;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\ChannelType;
use App\Support\ChannelTypes\Contracts\PhoneChannelType;
use App\Support\Delivery\Transports\AppriseTransport;
use App\Support\Delivery\Transports\MailTransport;
use App\Support\Delivery\Transports\MessagingTransport;
use App\Support\Delivery\Transports\MobilePushTransport;
use App\Support\Delivery\Transports\SmsTransport;
use App\Support\Delivery\Transports\Transport;
use App\Support\Delivery\Transports\TwilioVoiceTransport;
use App\Support\Delivery\Transports\WebhookTransport;
use App\Support\Delivery\Transports\WebPushTransport;
use App\Support\MessagePrefix;

class ChannelDispatcher
{
    /**
     * Why a channel whose owner is below the type's catalog `min_tier` sends nothing.
     */
    public const TIER_REFUSAL = 'This channel needs the Plus plan or higher.';

    /**
     * Why an unconfirmed channel of any type sends nothing but its confirmation code.
     */
    public const UNCONFIRMED_REFUSAL = 'This channel has not been confirmed yet.';

    /**
     * The same refusal for a phone type, whose destination is a number.
     */
    public const PENDING_DELETION_REFUSAL = 'This account is scheduled for deletion.';

    public const UNCONFIRMED_NUMBER_REFUSAL = 'This number has not been confirmed yet.';

    public function __construct(private readonly ChannelTypeRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function send(NotificationChannel $channel, string $body, ?string $title = null, array $meta = []): DeliveryResult
    {
        $refusal = $this->refusalFor($channel, $meta);

        if ($refusal !== null) {
            return DeliveryResult::failed($refusal);
        }

        $type = $this->registry->find($channel->channel_type);

        $title = $title !== null ? MessagePrefix::apply($title) : null;

        return $this->transportFor($type->transport())->send($channel, $body, $title, $meta);
    }

    /**
     * Sets a phone's app-icon badge without notifying it, through the same refusals as an alert. Only a phone
     * push channel takes one, and only an iPhone's (MobilePushTransport::sendBadge()).
     */
    public function sendAppBadge(NotificationChannel $channel, int $badge): DeliveryResult
    {
        $refusal = $this->refusalFor($channel);

        if ($refusal !== null) {
            return DeliveryResult::failed($refusal);
        }

        if ($this->registry->find($channel->channel_type)?->transport() !== ChannelTransport::MobilePush) {
            return DeliveryResult::failed('Only a phone push channel has an app badge.');
        }

        return app(MobilePushTransport::class)->sendBadge($channel, $badge);
    }

    /**
     * Why this channel would be refused right now, or null when a send would reach its transport. It makes no
     * HTTP call. The meta matters only for a confirmation code; without it the answer is the one for an alert.
     *
     * @param  array<string, mixed>  $meta
     */
    public function refusalFor(NotificationChannel $channel, array $meta = []): ?string
    {
        $type = $this->registry->find($channel->channel_type);

        if ($type === null) {
            return "Unknown channel type [{$channel->channel_type}].";
        }

        return $this->refusal($channel, $type, $meta);
    }

    /**
     * Why a send must not go out, checked before any transport or HTTP call: an owner below
     * the type's catalog `min_tier` (a downgraded subscriber's channel is kept and refused,
     * and works again on re-upgrade), or an unverified channel of any type getting anything but its
     * confirmation code. The code is exempt from the second check only, never the first.
     *
     * @param  array<string, mixed>  $meta
     */
    private function refusal(NotificationChannel $channel, ChannelType $type, array $meta): ?string
    {
        if ($channel->user?->isPendingDeletion()) {
            return self::PENDING_DELETION_REFUSAL;
        }

        if ($channel->user !== null && ! $channel->user->meetsChannelTier($type->slug())) {
            return self::TIER_REFUSAL;
        }

        if (! $channel->isVerified() && ($meta['type'] ?? null) !== 'confirmation') {
            return $type instanceof PhoneChannelType ? self::UNCONFIRMED_NUMBER_REFUSAL : self::UNCONFIRMED_REFUSAL;
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
            ChannelTransport::TwilioVoice => app(TwilioVoiceTransport::class),
        };
    }
}
