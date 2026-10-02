<?php

namespace App\Console\Commands;

use App\Actions\Weather\BuildDailySummaryMessage;
use App\Actions\Weather\BuildDailySummaryReport;
use App\Actions\Weather\EvaluateThresholdAlert;
use App\Actions\Weather\GetGridpointForecastData;
use App\Enums\AlertCategory;
use App\Enums\MessageBodyStyle;
use App\Jobs\DeliverScheduledAlert;
use App\Models\AlertRule;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Evaluates and delivers Threshold Alerts and Daily Weather Summary rules —
 * the two categories with no inbound product to react to, just a
 * subscriber-chosen delivery_time to re-check against. Intended to run
 * every 15 minutes (see bootstrap/app.php); each rule fires at most once
 * per calendar day in its own location's timezone, tracked via
 * last_scheduled_run_on.
 *
 * A rule only advances last_scheduled_run_on once real forecast data was
 * actually fetched — a transient NWS API failure leaves it unset so the
 * next tick (within 15 minutes) retries, rather than silently skipping the
 * whole day. "Checked, but the threshold wasn't crossed" is a real, final
 * answer for the day and does advance it.
 */
class DispatchScheduledAlerts extends Command
{
    protected $signature = 'alerts:dispatch-scheduled';

    protected $description = 'Evaluate and deliver due Threshold Alert and Daily Weather Summary rules';

    public function handle(
        GetGridpointForecastData $getGridpointData,
        EvaluateThresholdAlert $evaluateThreshold,
        BuildDailySummaryMessage $buildSummary,
        BuildDailySummaryReport $buildReport,
    ): int {
        $rules = AlertRule::query()
            ->where('is_active', true)
            ->whereIn('category', [AlertCategory::Threshold, AlertCategory::DailySummary])
            ->whereNotNull('delivery_time')
            ->whereNotNull('location_id')
            ->with(['location', 'notificationChannels'])
            ->get();

        $dispatched = 0;

        foreach ($rules as $rule) {
            if ($rule->location === null || ! $rule->location->hasFix()) {
                continue;
            }

            $timezone = $rule->location->timezone ?? config('app.timezone');
            $now = Carbon::now($timezone);

            if ($rule->last_scheduled_run_on?->isSameDay($now)) {
                continue;
            }

            $deliveryTime = $now->copy()->setTimeFromTimeString($rule->delivery_time);

            if ($now->lt($deliveryTime)) {
                continue;
            }

            $message = match ($rule->category) {
                AlertCategory::Threshold => $this->evaluateThreshold($rule, $getGridpointData, $evaluateThreshold),
                AlertCategory::DailySummary => $buildSummary($rule->location),
                default => null,
            };

            if ($message === null) {
                // Data unavailable this tick — leave last_scheduled_run_on
                // unset so the next run (within 15 minutes) retries today.
                continue;
            }

            $rule->forceFill(['last_scheduled_run_on' => $now->toDateString()])->save();

            if ($message === false) {
                // Checked successfully; the threshold just wasn't crossed.
                continue;
            }

            $report = $this->reportFor($rule, $buildReport);

            foreach ($rule->notificationChannels as $channel) {
                DeliverScheduledAlert::dispatch($rule->id, $channel->id, $rule->displayName(), $message, $report);
                $dispatched++;
            }
        }

        $this->info("Dispatched {$dispatched} scheduled alert(s).");

        return self::SUCCESS;
    }

    /**
     * The Full Daily Summary's structured payload, built once per rule (not per
     * channel). Null for everything else: Threshold rules, Brief/Free/unset
     * message styles (only an explicit Full counts), and a Full rule with no
     * attached channel that could render it — building the report costs several
     * NWS requests, so it is skipped when nothing could use it. A build failure
     * degrades to "Brief only", never to "no delivery".
     *
     * @return array<string, mixed>|null
     */
    private function reportFor(AlertRule $rule, BuildDailySummaryReport $buildReport): ?array
    {
        if ($rule->category !== AlertCategory::DailySummary || $rule->effectiveMessageBodyStyle() !== MessageBodyStyle::Full) {
            return null;
        }

        $registry = app(ChannelTypeRegistry::class);

        if (! $rule->notificationChannels->contains(fn ($channel): bool => $registry->find($channel->channel_type)?->supportsRichContent() === true)) {
            return null;
        }

        try {
            return $buildReport($rule->location, (bool) $rule->user->is_test_account);
        } catch (Throwable $e) {
            Log::warning('Daily summary report build failed', ['rule_id' => $rule->id, 'exception' => $e::class]);

            return null;
        }
    }

    /**
     * @return string|false|null string to send, false if checked but not
     *                           crossed, null if data was unavailable
     */
    private function evaluateThreshold(AlertRule $rule, GetGridpointForecastData $getGridpointData, EvaluateThresholdAlert $evaluate): string|false|null
    {
        $gridpointData = $getGridpointData($rule->location);

        if ($gridpointData === null) {
            return null;
        }

        $result = $evaluate($rule, $gridpointData);

        if ($result === null) {
            return false;
        }

        $variable = $rule->variable->label();
        $direction = $rule->direction->label();
        $unit = $rule->variable->unit();
        $threshold = $this->trimmedNumber((float) $rule->threshold_value);
        $peakValue = $this->trimmedNumber($result['value']);
        $at = $result['at']->format('g:i A');

        return "{$variable} {$direction} {$threshold}{$unit} near {$rule->location->label} "
            ."(peak {$peakValue}{$unit} around {$at}).";
    }

    private function trimmedNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }
}
