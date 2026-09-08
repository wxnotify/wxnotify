<?php

namespace App\Actions\Weather;

use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Jobs\DeliverWeatherAlert;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Matches a newly ingested NWWS product against active AlertRules and
 * dispatches DeliverWeatherAlert per matched rule x notification channel.
 * Mirrors App\Actions\Visibility\MatchActivityAlertRules's shape.
 *
 * AlertCategory::SpcWpc is deliberately excluded from the category filter —
 * see this feature's plan document's "Scope decision" for why.
 */
class MatchWeatherAlertRules
{
    public function __invoke(IngestedProduct $product): void
    {
        $categories = [AlertCategory::ForecastDiscussion];
        if ($product->product_id !== null) {
            $categories[] = AlertCategory::WeatherAlerts;
        }

        try {
            $rules = AlertRule::query()
                ->where('is_active', true)
                ->whereIn('category', $categories)
                ->where(function (Builder $query) use ($product) {
                    $query->where('category', AlertCategory::ForecastDiscussion)
                        ->orWhere(fn (Builder $q) => $q->where('category', AlertCategory::WeatherAlerts)
                            ->whereHas('products', fn (Builder $q) => $q->whereKey($product->product_id)));
                })
                ->get()
                ->filter(fn (AlertRule $rule): bool => $this->matchesGeography($rule, $product));
        } catch (Throwable $e) {
            logger()->error('Failed to query matching AlertRules', [
                'ingested_product_id' => $product->id,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        $rules->each(function (AlertRule $rule) use ($product): void {
            $this->dispatchOrSuppress($rule, $product);
        });
    }

    private function dispatchOrSuppress(AlertRule $rule, IngestedProduct $product): void
    {
        $suppressed = $this->isWithinQuietHours($rule);

        $rule->notificationChannels->each(function (NotificationChannel $channel) use ($rule, $product, $suppressed): void {
            try {
                if ($suppressed) {
                    DeliveryLog::create([
                        'alert_rule_id' => $rule->id,
                        'notification_channel_id' => $channel->id,
                        'user_id' => $rule->user_id,
                        'channel_type' => $channel->channel_type,
                        'status' => 'suppressed',
                        'dispatched_at' => now(),
                    ]);

                    return;
                }

                DeliverWeatherAlert::dispatch($rule->id, $channel->id, $product->id);
            } catch (Throwable $e) {
                logger()->error('Failed to dispatch DeliverWeatherAlert', [
                    'rule_id' => $rule->id,
                    'channel_id' => $channel->id,
                    'ingested_product_id' => $product->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        });
    }

    private function matchesGeography(AlertRule $rule, IngestedProduct $product): bool
    {
        if ($rule->category === AlertCategory::ForecastDiscussion) {
            return $this->matchesWfo($rule->wfo, $product->cccc);
        }

        return match ($rule->matching_mode) {
            MatchingMode::Nationwide => true,
            MatchingMode::Wfo => $this->matchesWfo($rule->wfo, $product->cccc),
            MatchingMode::Location, MatchingMode::CountyZone, MatchingMode::Polygon => false, // implemented in later tasks
            null => false,
        };
    }

    private function matchesWfo(?string $wfo, ?string $cccc): bool
    {
        if ($wfo === null || $cccc === null) {
            return false;
        }

        return strtoupper($wfo) === strtoupper(substr($cccc, 1));
    }

    /**
     * Nationwide/Wfo/ForecastDiscussion rules have no Location at all —
     * "quiet hours" implicitly means "my nighttime", which has no
     * well-defined answer without one. These always deliver regardless of
     * any quiet_hours_start/end value on the row.
     */
    private function isWithinQuietHours(AlertRule $rule): bool
    {
        if ($rule->quiet_hours_start === null || $rule->quiet_hours_end === null) {
            return false;
        }

        if (! in_array($rule->matching_mode, [MatchingMode::Location, MatchingMode::Polygon, MatchingMode::CountyZone], true)) {
            return false;
        }

        $timezone = $rule->location?->timezone;
        if ($timezone === null) {
            return false;
        }

        $now = Carbon::now($timezone);
        $start = $now->copy()->setTimeFromTimeString($rule->quiet_hours_start);
        $end = $now->copy()->setTimeFromTimeString($rule->quiet_hours_end);

        if ($start->lessThanOrEqualTo($end)) {
            return $now->between($start, $end);
        }

        return $now->greaterThanOrEqualTo($start) || $now->lessThanOrEqualTo($end);
    }
}
