<?php

namespace App\Support\Delivery\Transports;

use App\Enums\Integration;
use App\Mail\ChannelMessageMail;
use App\Models\NotificationChannel;
use App\Support\Delivery\DeliveryResult;
use App\Support\Integrations;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends through Laravel's configured mailer. Failure text is fixed: an exception
 * message can carry the SMTP host, credentials or the recipient address.
 */
class MailTransport implements Transport
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function send(NotificationChannel $channel, string $body, ?string $title, array $meta = []): DeliveryResult
    {
        if (! $this->relayEnabled()) {
            return DeliveryResult::failed('Email delivery is turned off on this server.');
        }

        $to = $channel->config['to'] ?? null;

        if (blank($to)) {
            return DeliveryResult::failed('This email channel is missing its address.');
        }

        try {
            Mail::to($to)->send(new ChannelMessageMail($title, $body));
        } catch (Throwable $e) {
            logger()->warning('Email channel send failed', ['notification_channel_id' => $channel->id, 'exception' => $e::class]);

            return DeliveryResult::failed('The mail server could not send the message.');
        }

        return DeliveryResult::delivered();
    }

    /**
     * Defaults to enabled when the settings cannot be read, matching ApplyEmailSettings.
     */
    private function relayEnabled(): bool
    {
        try {
            return Integrations::enabled(Integration::EmailRelay);
        } catch (Throwable) {
            return true;
        }
    }
}
