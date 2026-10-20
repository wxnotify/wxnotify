<?php

namespace App\Jobs;

use App\Actions\Weather\ComposeTemplatedAlertBody;
use App\Actions\Weather\RenderAlertSubject;
use App\Enums\ChannelTransport;
use App\Enums\MessageBodyStyle;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\Delivery\ChannelDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
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
 * to compose into something non-null takes priority. Otherwise the raw
 * product text is used (a CAP product's description, falling back to its
 * text). For every channel except email the result is capped at 280 or
 * 1500 characters per the rule's existing message_body_style via
 * Str::limit — the templated body bypasses that Brief cap entirely, since
 * section selection is what controls length in that case. An email
 * channel (the only one with no practical length limit) always gets the
 * body in full and unmodified, regardless of message_body_style:
 * Str::limit's word-preserving mode collapses every line break to a
 * space, which is fine for a short chat message but destroys a formatted
 * product in an inbox.
 *
 * The matched segment ids (see IngestedProductSegment) name the events this delivery is about: the subject lists each once, the templated body describes the first, and delivery_log_segment records them on the log row, including a skipped one. An id whose segment is gone by the time the job runs is ignored. An empty list (a non-VTEC product, a job queued before segments existed) keeps the bulletin's own product.
 */
class DeliverWeatherAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  list<int>  $segmentIds  the matched IngestedProductSegment ids, in bulletin order
     */
    public function __construct(
        public readonly int $ruleId,
        public readonly int $channelId,
        public readonly int $ingestedProductId,
        public readonly ?int $locationId = null,
        public readonly array $segmentIds = [],
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

        // A job serialized onto the queue by code predating $locationId (via
        // Illuminate\Queue\SerializesModels' native unserialize(), which
        // bypasses the constructor entirely) has this typed property left
        // uninitialized, not null. The `??` null-coalesce uses isset()
        // semantics, which is false for an uninitialized typed property
        // instead of throwing, so the property is read exactly once here
        // and never dereferenced again while potentially uninitialized.
        $locationId = $this->locationId ?? null;
        $location = $locationId !== null ? Location::find($locationId) : null;

        $segments = $product->matchedSegments($this->segmentIds ?? []);
        $eventNames = $product->eventNamesFor($segments);

        if (! $rule->is_active) {
            $this->logSkipped($rule, $channel, 'The rule was deactivated after this delivery was dispatched.', $product, app(RenderAlertSubject::class)($rule, $eventNames, $location, $product, $segments), $segments);

            return;
        }

        $templatedBody = app(ComposeTemplatedAlertBody::class)($rule, $product, $segments->first());
        $bodySource = $templatedBody ?? ($product->format === 'cap' ? ($product->description ?? $product->text) : $product->text);

        // Str::limit's word-preserving mode collapses every line break to a space (it exists for
        // single-line excerpts), so it must never touch a body bound for a real inbox. Email has
        // no length constraint that would justify running it in the first place.
        $isEmailChannel = app(ChannelTypeRegistry::class)->find($channel->channel_type)?->transport() === ChannelTransport::Mail;
        $maxLength = $templatedBody === null && $rule->effectiveMessageBodyStyle() === MessageBodyStyle::Brief ? 280 : 1500;
        $body = $isEmailChannel ? $bodySource : Str::limit($bodySource, $maxLength, preserveWords: true);

        $subject = app(RenderAlertSubject::class)($rule, $eventNames, $location, $product, $segments);

        $result = $dispatcher->send($channel, $body, $subject, [
            'type' => 'alert',
            'rule' => $rule->name,
            'product' => $eventNames === [] ? null : implode(' + ', $eventNames),
            'url' => $product->viewUrl(),
            'alert_rule_id' => $rule->id,
            'ingested_product_id' => $product->id,
        ]);

        $log = DeliveryLog::create([
            'alert_rule_id' => $rule->id,
            'notification_channel_id' => $channel->id,
            'user_id' => $rule->user_id,
            'channel_type' => $channel->channel_type,
            'status' => $result->success ? 'success' : 'failure',
            'category' => $rule->category->value,
            'error_detail' => $result->error,
            'subject' => $subject,
            'ingested_product_id' => $product->id,
            'dispatched_at' => now(),
        ]);

        $this->linkSegments($log, $segments);
    }

    /**
     * $product/$subject are only available from the "rule deactivated" skip
     * path (reached after $product is resolved) — the "rule/channel/product
     * gone" paths have nothing to render a subject from, so both stay null
     * there, and ingested_product_id (nullable) is simply omitted.
     */
    private function logSkipped(AlertRule $rule, NotificationChannel $channel, string $reason, ?IngestedProduct $product = null, ?string $subject = null, ?Collection $segments = null): void
    {
        $log = DeliveryLog::create([
            'alert_rule_id' => $rule->id,
            'notification_channel_id' => $channel->id,
            'user_id' => $rule->user_id,
            'channel_type' => $channel->channel_type,
            'status' => 'skipped',
            'category' => $rule->category->value,
            'error_detail' => $reason,
            'subject' => $subject,
            'ingested_product_id' => $product?->id,
            'dispatched_at' => now(),
        ]);

        $this->linkSegments($log, $segments ?? new Collection);
    }

    /**
     * @param  Collection<int, IngestedProductSegment>  $segments
     */
    private function linkSegments(DeliveryLog $log, Collection $segments): void
    {
        if ($segments->isNotEmpty()) {
            $log->segments()->attach($segments->modelKeys());
        }
    }
}
