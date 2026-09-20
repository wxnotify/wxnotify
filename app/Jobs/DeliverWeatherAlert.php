<?php

namespace App\Jobs;

use App\Actions\Weather\ComposeTemplatedAlertBody;
use App\Actions\Weather\RenderAlertSubject;
use App\Enums\MessageBodyStyle;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Support\Delivery\ChannelDispatcher;
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
 *
 * Body composition: a rule with an attached weather_alerts-category
 * AlertTemplate that App\Actions\Weather\ComposeTemplatedAlertBody manages
 * to compose into something non-null takes priority, always capped at 1500
 * characters. Otherwise the raw product text is used (a CAP product's
 * description, falling back to its text), capped at 280 or 1500 characters
 * per the rule's existing message_body_style — the templated body bypasses
 * that Brief cap entirely, since section selection is what controls length
 * in that case.
 */
class DeliverWeatherAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $ruleId,
        public readonly int $channelId,
        public readonly int $ingestedProductId,
    ) {}

    public function handle(ChannelDispatcher $dispatcher): void
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

        $templatedBody = app(ComposeTemplatedAlertBody::class)($rule, $product);
        $bodySource = $templatedBody ?? ($product->format === 'cap' ? ($product->description ?? $product->text) : $product->text);
        $maxLength = $templatedBody === null && $rule->effectiveMessageBodyStyle() === MessageBodyStyle::Brief ? 280 : 1500;

        $result = $dispatcher->send($channel, Str::limit($bodySource, $maxLength, preserveWords: true), app(RenderAlertSubject::class)($rule, $product->product), [
            'type' => 'alert',
            'rule' => $rule->name,
            'product' => $product->product?->name,
        ]);

        DeliveryLog::create([
            'alert_rule_id' => $rule->id,
            'notification_channel_id' => $channel->id,
            'user_id' => $rule->user_id,
            'channel_type' => $channel->channel_type,
            'status' => $result->success ? 'success' : 'failure',
            'error_detail' => $result->error,
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
