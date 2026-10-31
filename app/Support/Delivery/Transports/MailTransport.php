<?php

namespace App\Support\Delivery\Transports;

use App\Enums\Integration;
use App\Mail\ChannelMessageMail;
use App\Mail\DailySummaryMail;
use App\Mail\OutlookReportMail;
use App\Models\NotificationChannel;
use App\Support\Delivery\DeliveryResult;
use App\Support\Integrations;
use Illuminate\Mail\Mailable;
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
            Mail::to($to)->send($this->mailable($channel, $body, $title, $meta));
        } catch (Throwable $e) {
            logger()->warning('Email channel send failed', ['notification_channel_id' => $channel->id, 'exception' => $e::class]);

            return DeliveryResult::failed('The mail server could not send the message.');
        }

        return DeliveryResult::delivered();
    }

    /**
     * The rich daily summary and outlook report are rendered here, in their own guard, so a report
     * that cannot be rendered degrades to the Brief text instead of failing the
     * delivery. The log is fixed text: an exception message can carry report data.
     *
     * @param  array<string, mixed>  $meta
     */
    private function mailable(NotificationChannel $channel, string $body, ?string $title, array $meta): Mailable
    {
        $outlookReport = $meta['outlook_report'] ?? null;

        if (is_array($outlookReport)) {
            try {
                $images = $meta['outlook_report_images'] ?? [];
                $mail = new OutlookReportMail($title, $outlookReport, $body, is_array($images) ? $images : []);
                $mail->render();

                return $mail;
            } catch (Throwable $e) {
                logger()->warning('Outlook report email could not be rendered, sending the brief instead', ['notification_channel_id' => $channel->id, 'exception' => $e::class]);
            }
        }

        $summary = $meta['daily_summary'] ?? null;

        if (is_array($summary)) {
            try {
                $mail = new DailySummaryMail($title, $summary, $body, $meta['daily_summary_radar'] ?? null);
                $mail->render();

                return $mail;
            } catch (Throwable $e) {
                logger()->warning('Daily summary email could not be rendered, sending the brief instead', ['notification_channel_id' => $channel->id, 'exception' => $e::class]);
            }
        }

        return new ChannelMessageMail($title, $body, $meta['url'] ?? null);
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
