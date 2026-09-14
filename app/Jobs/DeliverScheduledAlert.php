<?php

namespace App\Jobs;

use App\Actions\Weather\RenderAlertSubject;
use App\Enums\MessageBodyStyle;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\NotificationChannel;
use App\Support\Apprise\AppriseApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Dispatched once per AlertRule x NotificationChannel by
 * App\Console\Commands\DispatchScheduledAlerts, for Threshold Alerts and
 * Daily Weather Summary — the two categories with no IngestedProduct behind
 * them, just a composed message. Mirrors App\Jobs\DeliverWeatherAlert's
 * DeliveryLog/Apprise shape exactly; the only difference is the message
 * arrives pre-built rather than being derived from a product's raw text.
 */
class DeliverScheduledAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $ruleId,
        public readonly int $channelId,
        public readonly string $title,
        public readonly string $body,
    ) {}

    public function handle(AppriseApiClient $client): void
    {
        $rule = AlertRule::find($this->ruleId);

        if ($rule === null) {
            logger()->warning('DeliverScheduledAlert: rule no longer exists', [
                'rule_id' => $this->ruleId,
                'channel_id' => $this->channelId,
            ]);

            return;
        }

        $channel = NotificationChannel::find($this->channelId);

        if ($channel === null) {
            logger()->warning('DeliverScheduledAlert: channel no longer exists', [
                'rule_id' => $rule->id,
                'channel_id' => $this->channelId,
            ]);

            return;
        }

        if (! $rule->is_active) {
            DeliveryLog::create([
                'alert_rule_id' => $rule->id,
                'notification_channel_id' => $channel->id,
                'user_id' => $rule->user_id,
                'channel_type' => $channel->channel_type,
                'status' => 'skipped',
                'error_detail' => 'The rule was deactivated after this delivery was dispatched.',
                'dispatched_at' => now(),
            ]);

            return;
        }

        $title = app(RenderAlertSubject::class)($rule);
        $body = $rule->message_body_style === MessageBodyStyle::Brief
            ? Str::limit($this->body, 280, preserveWords: true)
            : $this->body;

        $delivered = $client->notify(
            url: $channel->apprise_url,
            body: $body,
            title: $title,
        );

        DeliveryLog::create([
            'alert_rule_id' => $rule->id,
            'notification_channel_id' => $channel->id,
            'user_id' => $rule->user_id,
            'channel_type' => $channel->channel_type,
            'status' => $delivered ? 'success' : 'failure',
            'error_detail' => $delivered ? null : 'Apprise reported non-delivery.',
            'dispatched_at' => now(),
        ]);
    }
}
