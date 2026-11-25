<?php

namespace App\Jobs;

use App\Actions\Alerts\ResolveAlertPushSound;
use App\Actions\Alerts\StoreOutlookReportRun;
use App\Actions\Weather\GetRadarSnapshot;
use App\Actions\Weather\GetSpcOutlookGraphic;
use App\Actions\Weather\RenderAlertSubject;
use App\Enums\AlertCategory;
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
use Throwable;

/**
 * Dispatched once per AlertRule x NotificationChannel by
 * App\Console\Commands\DispatchScheduledAlerts, for Threshold Alerts and
 * Daily Weather Summary, and by DispatchOutlookReports for the Severe Weather
 * Outlook Report (whose passed-in title is used as the subject) — categories
 * with no IngestedProduct behind them, just a composed message. Mirrors App\Jobs\DeliverWeatherAlert's
 * DeliveryLog/Apprise shape exactly; the only difference is the message
 * arrives pre-built rather than being derived from a product's raw text.
 */
class DeliverScheduledAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>|null  $report  the Full Daily Summary's (or the outlook report's) structured payload; null for Brief, Free, Threshold, and for jobs queued before this property existed
     * @param  string|null  $batchKey  shared by every job of one run of the rule (one per channel), so the app shows the run as one alert; null (or uninitialized) for jobs queued before this property existed
     */
    public function __construct(
        public readonly int $ruleId,
        public readonly int $channelId,
        public readonly string $title,
        public readonly string $body,
        public readonly ?array $report = null,
        public readonly ?string $batchKey = null,
    ) {
        // The worker reads `deliveries` before `default`, so a summary or threshold alert never waits
        // behind a burst of products being processed (see compose.yaml's queue service).
        $this->onQueue('deliveries');
    }

    public function handle(ChannelDispatcher $dispatcher): void
    {
        $batchKey = $this->batchKey ?? null;

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
                'category' => $rule->category->value,
                'error_detail' => 'The rule was deactivated after this delivery was dispatched.',
                'subject' => $rule->displayName(),
                'batch_key' => $batchKey,
                'dispatched_at' => now(),
            ]);

            return;
        }

        // The outlook report's subject is the per-day emoji line built at dispatch, not the rule's name.
        $isOutlookReport = $rule->category === AlertCategory::OutlookReport;
        $title = $isOutlookReport ? $this->title : app(RenderAlertSubject::class)($rule);
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
            'alert_rule_id' => $rule->id,
            'ingested_product_id' => null,
            'batch_key' => $batchKey,
            'push_sound' => $this->pushSound($channel, $rule),
        ];

        // A job serialized before $report existed (SerializesModels' native
        // unserialize() bypasses the constructor) has this typed readonly property
        // genuinely uninitialized, not null. `??` has isset() semantics, which is
        // false for an uninitialized property instead of throwing, so it is read
        // exactly once here and never dereferenced again.
        $report = $this->report ?? null;

        if ($isOutlookReport) {
            // A Brief outlook report is the Brief everywhere, even for a job that still carries a report.
            if ($report !== null && $channelType?->supportsRichContent() === true && $rule->effectiveMessageBodyStyle() !== MessageBodyStyle::Brief) {
                $meta['outlook_report'] = $report;
                // Fetched here, never queued: image bytes would bloat the queue payload (.ai/rules/jobs.md).
                $meta['outlook_report_images'] = $this->outlookGraphics($report);
            }
        } elseif ($report !== null && $channelType?->supportsRichContent() === true) {
            $radarSite = $report['radar_site'] ?? null;

            $meta['daily_summary'] = $report;
            $meta['daily_summary_radar'] = is_string($radarSite) ? app(GetRadarSnapshot::class)($radarSite) : null;
        }

        $result = $dispatcher->send($channel, $body, $title, $meta);

        if ($isOutlookReport && $report !== null && $batchKey !== null && $result->success && $channel->channel_type === 'mobile_push' && $rule->effectiveMessageBodyStyle() !== MessageBodyStyle::Brief) {
            $this->keepOutlookRun($rule, $batchKey, $report, $meta['outlook_report_images'] ?? null);
        }

        // ingested_product_id stays null (the column's default): Threshold
        // Alerts and Daily Weather Summary have no IngestedProduct behind
        // them at all, per this job's own docblock.
        DeliveryLog::create([
            'alert_rule_id' => $rule->id,
            'notification_channel_id' => $channel->id,
            'user_id' => $rule->user_id,
            'channel_type' => $channel->channel_type,
            'status' => $result->success ? 'success' : 'failure',
            'category' => $rule->category->value,
            'error_detail' => $result->error,
            'subject' => $title,
            'batch_key' => $batchKey,
            'dispatched_at' => now(),
        ]);
    }

    /**
     * The sound the rule chose for this alert (the `other` kind), for a phone only (no other channel plays one).
     * A failing lookup never loses the alert: the push goes out with the phone's default sound and only the
     * exception class is logged.
     */
    private function pushSound(NotificationChannel $channel, AlertRule $rule): ?string
    {
        if ($channel->channel_type !== 'mobile_push') {
            return null;
        }

        try {
            return app(ResolveAlertPushSound::class)($rule);
        } catch (Throwable $e) {
            logger()->warning('Push sound lookup failed: '.$e::class);

            return null;
        }
    }

    /**
     * Keeps the run (and a copy of its maps) for the app; a storage failure never fails the delivery.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, array{bytes: string, mime: string}|null>|null  $graphics  already fetched for this job, else fetched here
     */
    private function keepOutlookRun(AlertRule $rule, string $batchKey, array $report, ?array $graphics): void
    {
        try {
            app(StoreOutlookReportRun::class)($rule, $batchKey, $report, $graphics ?? $this->outlookGraphics($report));
        } catch (Throwable $e) {
            logger()->warning('Outlook report run could not be stored', ['exception' => $e::class]);
        }
    }

    /**
     * The SPC graphics of the outlook report, keyed day1, day2, day3 and day48: only those of available
     * days are fetched (day48 when any of days 4-8 is available), all at once; the rest are null.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, array{bytes: string, mime: string}|null>
     */
    private function outlookGraphics(array $report): array
    {
        $isAvailable = fn (mixed $day): bool => is_array($day) && ($day['available'] ?? false) === true;
        $wanted = [
            'day1' => $isAvailable($report['days'][1] ?? null),
            'day2' => $isAvailable($report['days'][2] ?? null),
            'day3' => $isAvailable($report['days'][3] ?? null),
            'day48' => collect($report['extended'] ?? [])->contains($isAvailable),
        ];

        $fetched = app(GetSpcOutlookGraphic::class)->many(array_keys(array_filter($wanted)));

        return array_map(fn (string $key): ?array => $fetched[$key] ?? null, array_combine(array_keys($wanted), array_keys($wanted)));
    }
}
