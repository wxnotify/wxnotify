<?php

namespace App\Console\Commands;

use App\Actions\Weather\BuildOutlookReport;
use App\Actions\Weather\FetchSpcOutlooks;
use App\Actions\Weather\FindActivePwo;
use App\Enums\AlertCategory;
use App\Enums\MessageBodyStyle;
use App\Enums\OutlookReportMode;
use App\Enums\Tier;
use App\Jobs\DeliverScheduledAlert;
use App\Models\AlertRule;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\Day1OutlookFreshness;
use App\Support\OutlookReportText;
use App\Support\Timezones;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends the daily Severe Weather Outlook Report, a paid-tier feature: rules of Free owners are skipped
 * (no fetch, no stamp). A Brief rule sends the Brief to every channel; otherwise rich channels also
 * get the Full report. Runs every 15 minutes (see bootstrap/app.php); each
 * rule sends at most once per local day at or after its send time, both in the rule owner's timezone
 * (Timezones::forRule(): the profile timezone, else UTC), tracked by last_scheduled_run_on (the local
 * date), like DispatchScheduledAlerts. The grace and Day 1's expected cycle compare instants.
 * Because the stamp is a local date, changing the profile timezone can skip or repeat one day's report.
 * An outlook rule has no location_id, so Timezones::forRule() reads no Location (a null foreign key
 * resolves without a query) and needs no eager load.
 *
 * SPC is asked once per run, only when a rule is due, and the result is shared by every due rule.
 * A Day 1 that is not yet the expected cycle (or is unavailable) holds the rule back, unstamped, for
 * up to 60 minutes past its send time; after that the report goes out as fetched (an unavailable
 * Day 1 then shows as missing). A rule that fails is logged by exception class, left unstamped and
 * does not stop the others.
 *
 * Known limitation: the grace never rolls over the owner's local midnight, so a rule whose send time
 * is later than 22:45 local (the form's cap; only possible through a forged request) gets no report
 * on a day its Day 1 stays stale past that midnight.
 */
class DispatchOutlookReports extends Command
{
    private const GRACE_MINUTES = 60;

    protected $signature = 'alerts:dispatch-outlook-reports';

    protected $description = 'Deliver due Severe Weather Outlook Report rules';

    public function handle(FetchSpcOutlooks $fetchOutlooks, BuildOutlookReport $buildReport, FindActivePwo $findPwo): int
    {
        $now = Carbon::now('UTC');

        $rules = AlertRule::query()
            ->where('is_active', true)
            ->where('category', AlertCategory::OutlookReport)
            ->whereNotNull('delivery_time')
            // A coarse pre-filter only: no local date is later than the UTC date plus one day.
            ->where(fn (Builder $query) => $query->whereNull('last_scheduled_run_on')->orWhere('last_scheduled_run_on', '<', $now->copy()->addDay()->toDateString()))
            ->with(['user', 'locations', 'notificationChannels'])
            ->orderBy('id')
            ->get()
            // Paid-only: a rule kept after its owner moved to Free stays, unstamped, and sends nothing.
            ->filter(fn (AlertRule $rule): bool => $rule->user->tier() !== Tier::Free && $this->isDue($rule, $now));

        if ($rules->isEmpty()) {
            $this->info('No outlook reports due.');

            return self::SUCCESS;
        }

        $outlooks = $fetchOutlooks();
        $pwo = $this->activePwo($findPwo, $outlooks['day_1']['issued_at'] ?? null);
        $dispatched = 0;

        foreach ($rules as $rule) {
            try {
                $dispatched += $this->deliver($rule, $outlooks, $pwo, $buildReport, $now);
            } catch (Throwable $e) {
                Log::warning('Outlook report failed', ['rule_id' => $rule->id, 'exception' => $e::class]);
            }
        }

        $this->info("Dispatched {$dispatched} outlook report(s).");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $outlooks
     * @return int the number of jobs dispatched
     */
    private function deliver(AlertRule $rule, array $outlooks, ?IngestedProduct $pwo, BuildOutlookReport $buildReport, Carbon $now): int
    {
        $sendTime = $this->sendTime($rule, $now);
        $today = $this->localNow($rule, $now)->toDateString();

        if (! Day1OutlookFreshness::isCurrent($outlooks['day_1'] ?? null, $sendTime)
            && $now->lt($sendTime->copy()->addMinutes(self::GRACE_MINUTES))) {
            return 0;
        }

        $payload = $buildReport($outlooks, $rule->locations, $pwo, $today);

        $sendNothing = $rule->report_mode === OutlookReportMode::OnlyWhenForecast
            && ! $payload['something_forecast']
            && ! $payload['day1_missing'];

        $title = OutlookReportText::subject($payload);
        $body = OutlookReportText::brief($payload);
        $report = $this->wantsFullReport($rule) ? $payload : null;
        $batchKey = (string) Str::uuid();

        return DB::transaction(function () use ($rule, $today, $sendNothing, $title, $body, $report, $batchKey): int {
            $rule->forceFill(['last_scheduled_run_on' => $today])->save();

            if ($sendNothing) {
                return 0;
            }

            foreach ($rule->notificationChannels as $channel) {
                DeliverScheduledAlert::dispatch($rule->id, $channel->id, $title, $body, $report, $batchKey);
            }

            return $rule->notificationChannels->count();
        });
    }

    /**
     * Not yet sent on the owner's local date, and that date's send time has come.
     */
    private function isDue(AlertRule $rule, Carbon $now): bool
    {
        $stamp = $rule->last_scheduled_run_on?->toDateString();

        if ($stamp !== null && $stamp >= $this->localNow($rule, $now)->toDateString()) {
            return false;
        }

        return $now->gte($this->sendTime($rule, $now));
    }

    private function localNow(AlertRule $rule, Carbon $now): Carbon
    {
        return $now->copy()->setTimezone(Timezones::forRule($rule));
    }

    /**
     * Today's send time in the owner's timezone, as an instant (daylight saving applied for the date).
     */
    private function sendTime(AlertRule $rule, Carbon $now): Carbon
    {
        return $this->localNow($rule, $now)->setTimeFromTimeString($rule->outlookSendTime());
    }

    /**
     * The Full report goes only to a rule that is not Brief (Full or unset) and has a channel that can
     * render it (email) or keep it for the app (a phone, which still gets the Brief text as its push);
     * a Brief rule sends the Brief text to every channel, email included.
     */
    private function wantsFullReport(AlertRule $rule): bool
    {
        if ($rule->effectiveMessageBodyStyle() === MessageBodyStyle::Brief) {
            return false;
        }

        $registry = app(ChannelTypeRegistry::class);

        return $rule->notificationChannels->contains(fn (NotificationChannel $channel): bool => $channel->channel_type === 'mobile_push' || $registry->find($channel->channel_type)?->supportsRichContent() === true);
    }

    private function activePwo(FindActivePwo $findPwo, ?string $day1IssuedAt): ?IngestedProduct
    {
        try {
            return $findPwo($day1IssuedAt);
        } catch (Throwable $e) {
            Log::warning('Active PWO lookup failed', ['exception' => $e::class]);

            return null;
        }
    }
}
