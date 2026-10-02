<?php

namespace App\Jobs;

use App\Actions\Weather\GetRadarSnapshot;
use App\Actions\Weather\RenderAlertSubject;
use App\Enums\ChannelTransport;
use App\Enums\MessageBodyStyle;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\Delivery\ChannelDispatcher;
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

    /**
     * @param  array<string, mixed>|null  $report  the Full Daily Summary's structured payload; null for Brief, Free, Threshold, and for jobs queued before this property existed
     */
    public function __construct(
        public readonly int $ruleId,
        public readonly int $channelId,
        public readonly string $title,
        public readonly string $body,
        public readonly ?array $report = null,
    ) {}

    public function handle(ChannelDispatcher $dispatcher): void
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
                'subject' => $rule->displayName(),
                'dispatched_at' => now(),
            ]);

            return;
        }

        $title = app(RenderAlertSubject::class)($rule);
        $channelType = app(ChannelTypeRegistry::class)->find($channel->channel_type);
        // Str::limit's word-preserving mode collapses line breaks to spaces, so it must never
        // touch a body bound for a real inbox — see DeliverWeatherAlert for the same rule.
        $isEmailChannel = $channelType?->transport() === ChannelTransport::Mail;
        $body = (! $isEmailChannel && $rule->effectiveMessageBodyStyle() === MessageBodyStyle::Brief)
            ? Str::limit($this->body, 280, preserveWords: true)
            : $this->body;

        $meta = [
            'type' => 'alert',
            'rule' => $rule->name,
        ];

        // A job serialized before $report existed (SerializesModels' native
        // unserialize() bypasses the constructor) has this typed readonly property
        // genuinely uninitialized, not null. `??` has isset() semantics, which is
        // false for an uninitialized property instead of throwing, so it is read
        // exactly once here and never dereferenced again.
        $report = $this->report ?? null;

        if ($report !== null && $channelType?->supportsRichContent() === true) {
            $radarSite = $report['radar_site'] ?? null;

            $meta['daily_summary'] = $report;
            $meta['daily_summary_radar'] = is_string($radarSite) ? app(GetRadarSnapshot::class)($radarSite) : null;
        }

        $result = $dispatcher->send($channel, $body, $title, $meta);

        // ingested_product_id stays null (the column's default): Threshold
        // Alerts and Daily Weather Summary have no IngestedProduct behind
        // them at all, per this job's own docblock.
        DeliveryLog::create([
            'alert_rule_id' => $rule->id,
            'notification_channel_id' => $channel->id,
            'user_id' => $rule->user_id,
            'channel_type' => $channel->channel_type,
            'status' => $result->success ? 'success' : 'failure',
            'error_detail' => $result->error,
            'subject' => $title,
            'dispatched_at' => now(),
        ]);
    }
}
