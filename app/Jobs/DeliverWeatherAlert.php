<?php

namespace App\Jobs;

use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Support\Apprise\AppriseApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Dispatched once per AlertRule x NotificationChannel matched by
 * App\Actions\Weather\MatchWeatherAlertRules. Takes ids rather than model
 * instances so a rule/channel/product deleted between dispatch and
 * processing is a no-op rather than a failed job.
 *
 * Unlike App\Jobs\SendActivityAlert, a delivery failure here is a terminal
 * outcome recorded in DeliveryLog, not a retried job — the design doc
 * specifies writing a failure row, not retry-and-throw.
 *
 * Every no-op path is still traceable, for the same reason
 * MatchWeatherAlertRules audits quiet-hours suppression rather than just
 * silently skipping it: delivery_logs.channel_type is a NOT NULL column, so
 * a full audit row is only possible once a real NotificationChannel is in
 * hand (the "product gone" and "rule deactivated" branches, both reached
 * after $channel is resolved) — the "rule gone"/"channel gone" branches
 * fall back to a log line instead of forcing a schema change or a
 * meaningless sentinel channel_type value.
 */
class DeliverWeatherAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $ruleId,
        public readonly int $channelId,
        public readonly int $ingestedProductId,
    ) {}

    public function handle(AppriseApiClient $client): void
    {
        $rule = AlertRule::find($this->ruleId);

        if ($rule === null) {
            logger()->warning('DeliverWeatherAlert: rule no longer exists', [
                'rule_id' => $this->ruleId,
                'channel_id' => $this->channelId,
                'ingested_product_id' => $this->ingestedProductId,
            ]);

            return;
        }

        $channel = NotificationChannel::find($this->channelId);

        if ($channel === null) {
            logger()->warning('DeliverWeatherAlert: channel no longer exists', [
                'rule_id' => $rule->id,
                'channel_id' => $this->channelId,
                'ingested_product_id' => $this->ingestedProductId,
            ]);

            return;
        }

        $product = IngestedProduct::find($this->ingestedProductId);

        if ($product === null) {
            $this->logSkipped($rule, $channel, 'The ingested product no longer exists.');

            return;
        }

        if (! $rule->is_active) {
            $this->logSkipped($rule, $channel, 'The rule was deactivated after this delivery was dispatched.');

            return;
        }

        $delivered = $client->notify(
            url: $channel->apprise_url,
            body: Str::limit($product->text, 1500, preserveWords: true),
            title: $rule->displayName(),
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

    private function logSkipped(AlertRule $rule, NotificationChannel $channel, string $reason): void
    {
        DeliveryLog::create([
            'alert_rule_id' => $rule->id,
            'notification_channel_id' => $channel->id,
            'user_id' => $rule->user_id,
            'channel_type' => $channel->channel_type,
            'status' => 'skipped',
            'error_detail' => $reason,
            'dispatched_at' => now(),
        ]);
    }
}
