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
 * processing is a silent, intentional no-op.
 *
 * Unlike App\Jobs\SendActivityAlert, a delivery failure here is a terminal
 * outcome recorded in DeliveryLog, not a retried job — the design doc
 * specifies writing a failure row, not retry-and-throw.
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
        $channel = NotificationChannel::find($this->channelId);
        $product = IngestedProduct::find($this->ingestedProductId);

        if ($rule === null || $channel === null || $product === null || ! $rule->is_active) {
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
}
