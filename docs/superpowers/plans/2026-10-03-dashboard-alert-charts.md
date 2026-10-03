# Dashboard Alert Charts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Two new subscriber-dashboard widgets — a line chart of alerts delivered per day and a pie chart of alerts delivered by category — over the subscriber's own successful deliveries.

**Architecture:** `delivery_logs` gains a `category` column (backfilled, and written at all five places a log row is created) so the pie stays accurate after a rule is deleted. A small `GetAlertDeliveryStats` action does the querying (timezone-aware day buckets, zero-filled days, category grouping); two thin Filament `ChartWidget`s (Chart.js is already bundled) render it with a 7/30/90-day range filter.

**Tech Stack:** Laravel 13 / PHP 8.4, Filament v5 `ChartWidget`, PostgreSQL, Pest.

**Spec:** none — this is a bounded feature approved in chat (the design is reproduced under "Approved design" below, and is the binding authority).

## Approved design (binding)

- **"Alert sent" = a successful delivery**: each `delivery_logs` row with `status = 'success'` (one per channel; failures, suppressed and skipped rows are excluded). Widgets are titled **"Alerts delivered per day"** and **"Alerts delivered by category"**.
- **Category source**: a nullable `delivery_logs.category` column, written at delivery time and backfilled from `alert_rules` for existing rows; rows with no category (rule already gone) appear as **"Other"**.
- **Day boundaries**: the timezone of the subscriber's most recently added Location (`created_at` desc, then `id` desc), falling back to `UTC` when they have none or the stored zone is not a valid IANA name.
- **Range filter**: Filament's built-in dropdown on each widget — Last 7 / 30 / 90 days, default 30.
- **Line chart**: one point per day across the whole range, zero-filled, labels like "Oct 2".
- **Pie chart**: slices by `AlertCategory::label()`, largest first (ties by label), **"Other" last**, a fixed color per category, legend at the bottom.
- No polling (`ChartWidget` polls every 5 s by default — disable it); an empty period shows "No alerts delivered in this period."; every tier sees both; every query is scoped to `auth()->user()`.
- **Placement**: a two-up row under Recent Alerts (line chart left, pie right).
- **Not included**: per-channel breakdowns, drill-down/export, admin-panel versions.

## Global Constraints

- Counts include **only** `status = 'success'` rows belonging to the signed-in user (`delivery_logs.user_id`), within the range, in the user's day-boundary timezone.
- The migration is **idempotent** (guards the schema change with `Schema::hasColumn`, runs the backfill every time) so a test can re-run it.
- `category` is written at **all five** `DeliveryLog::create` sites; no site may omit it: `DeliverWeatherAlert` (2), `DeliverScheduledAlert` (2), `MatchWeatherAlertRules` (1, the quiet-hours "suppressed" row). Each already has the rule in hand (`$rule->category->value`).
- Fixed-string logs only; tests must not depend on the real clock (pin `Carbon::setTestNow`); `DB_HOST` quirk below.
- Project conventions: Pest feature tests; `vendor/bin/pint --dirty --format agent` before each commit; commit directly to `main` (no push); commit messages end with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

## How to run tests (read this once)

The host cannot resolve the `pgsql` hostname and runs out of memory under `php artisan test`. Run Pest inside the app container:

```bash
docker exec wxnotify-nextgen-front-end-1 sh -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --compact tests/Feature/SomeTest.php'
```

Every "Run:" line below means that command with the stated test file(s). The full suite takes ~6 minutes: run it with `run_in_background`. Pint runs on the host: `vendor/bin/pint --dirty --format agent`.

## File Structure

| Path | Responsibility |
| --- | --- |
| `database/migrations/2026_10_03_100001_add_category_to_delivery_logs_table.php` | column + index + backfill |
| `app/Models/DeliveryLog.php` | `category` in `#[Fillable]` |
| `app/Jobs/DeliverWeatherAlert.php`, `app/Jobs/DeliverScheduledAlert.php`, `app/Actions/Weather/MatchWeatherAlertRules.php` | write `category` on every log row |
| `app/Actions/Dashboard/GetAlertDeliveryStats.php` | `timezoneFor()`, `perDay()`, `byCategory()` |
| `app/Filament/Dashboard/Widgets/Concerns/HasDeliveryChartRange.php` | shared 7/30/90 filter helpers |
| `app/Filament/Dashboard/Widgets/AlertsPerDayChart.php`, `AlertsByCategoryChart.php` | the two widgets |
| `app/Providers/Filament/DashboardPanelProvider.php` | register both widgets |
| `tests/Feature/DeliveryLogCategoryTest.php`, `GetAlertDeliveryStatsTest.php`, `AlertChartWidgetsTest.php` | tests |

---

### Task 1: Record the category on every delivery log

**Files:**
- Create: `database/migrations/2026_10_03_100001_add_category_to_delivery_logs_table.php`
- Modify: `app/Models/DeliveryLog.php`, `app/Jobs/DeliverWeatherAlert.php`, `app/Jobs/DeliverScheduledAlert.php`, `app/Actions/Weather/MatchWeatherAlertRules.php`
- Test: `tests/Feature/DeliveryLogCategoryTest.php`

**Interfaces:**
- Produces: `delivery_logs.category` (nullable string holding an `AlertCategory` value); `DeliveryLog` mass-assignable `category`; every log row created from a rule carries `$rule->category->value`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/DeliveryLogCategoryTest.php`:

```php
<?php

use App\Actions\Weather\MatchWeatherAlertRules;
use App\Jobs\DeliverScheduledAlert;
use App\Jobs\DeliverWeatherAlert;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Support\Delivery\ChannelDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('the migration backfills each existing log row from its rule and leaves orphaned rows null', function () {
    $threshold = AlertRule::factory()->create(); // category: threshold
    $lightning = AlertRule::factory()->lightning()->create();

    $withRule = DeliveryLog::factory()->for($threshold->user)->create(['alert_rule_id' => $threshold->id, 'category' => null]);
    $withOtherRule = DeliveryLog::factory()->for($lightning->user)->create(['alert_rule_id' => $lightning->id, 'category' => null]);
    $orphan = DeliveryLog::factory()->for($threshold->user)->create(['alert_rule_id' => null, 'category' => null]);
    $alreadySet = DeliveryLog::factory()->for($threshold->user)->create(['alert_rule_id' => $threshold->id, 'category' => 'daily_summary']);

    (require database_path('migrations/2026_10_03_100001_add_category_to_delivery_logs_table.php'))->up();

    expect($withRule->fresh()->category)->toBe('threshold')
        ->and($withOtherRule->fresh()->category)->toBe('lightning')
        ->and($orphan->fresh()->category)->toBeNull()
        ->and($alreadySet->fresh()->category)->toBe('threshold'); // the backfill is authoritative for rows that have a rule
});

test('the migration is safe to run when the column already exists', function () {
    $migration = require database_path('migrations/2026_10_03_100001_add_category_to_delivery_logs_table.php');

    $migration->up();
    $migration->up();

    expect(DB::getSchemaBuilder()->hasColumn('delivery_logs', 'category'))->toBeTrue();
});

test('a successful scheduled delivery records the rule\'s category', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $rule = AlertRule::factory()->dailySummary()->create();
    $channel = NotificationChannel::factory()->for($rule->user)->create(['apprise_url' => 'discord://token/id']);

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', 'Today: Sunny.'))->handle(app(ChannelDispatcher::class));

    expect(DeliveryLog::sole()->category)->toBe('daily_summary');
});

test('a skipped scheduled delivery (rule deactivated) records the rule\'s category too', function () {
    Http::fake();
    $rule = AlertRule::factory()->lightning()->create(['is_active' => false]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Lightning', 'body'))->handle(app(ChannelDispatcher::class));

    $log = DeliveryLog::sole();
    expect($log->status)->toBe('skipped')->and($log->category)->toBe('lightning');
});

test('a weather alert delivery records the rule\'s category, success and skipped alike', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $channel = NotificationChannel::factory()->for($rule->user)->create(['apprise_url' => 'discord://token/id']);
    $product = IngestedProduct::factory()->create();

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(ChannelDispatcher::class));

    $rule->update(['is_active' => false]);
    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(ChannelDispatcher::class));

    expect(DeliveryLog::orderBy('id')->pluck('category', 'status')->all())->toBe(['success' => 'weather_alerts', 'skipped' => 'weather_alerts']);
});

test('the quiet-hours suppressed row records the rule\'s category', function () {
    Queue::fake();
    Carbon::setTestNow('2026-10-02 09:00:00'); // 03:00 America/Denver: inside the rule's quiet hours
    $tornado = Product::where('code', 'TO.W')->sole();
    $location = App\Models\Location::factory()->create(['timezone' => 'America/Denver', 'forecast_zone_ugc' => 'COZ040']);
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => null,
        'quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00',
    ]);
    $rule->locations()->attach($location);
    $rule->products()->attach($tornado);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create(['product_id' => $tornado->id, 'ugc_codes' => ['COZ040']]));

    (new MatchWeatherAlertRules)($ingested);

    $log = DeliveryLog::sole();
    expect($log->status)->toBe('suppressed')->and($log->category)->toBe('weather_alerts');
    Carbon::setTestNow();
});
```

(The last test mirrors how `MatchWeatherAlertRulesTest.php` builds a quiet-hours rule — copy its exact setup helpers (e.g. `createIngestedProductWithoutMatching`) if that file defines them as global helpers; the shape above is the intent: a Location-mode Weather Alerts rule with quiet hours that cover "now", matched against a real product, producing one `suppressed` log row.)

- [ ] **Step 2: Run to verify it fails**

Run: `tests/Feature/DeliveryLogCategoryTest.php`
Expected: FAIL — the migration file does not exist / `category` is not a column.

- [ ] **Step 3: The migration**

`database/migrations/2026_10_03_100001_add_category_to_delivery_logs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * delivery_logs.alert_rule_id is nulled when a rule is deleted, which would
     * make a per-category history lose every row whose rule is gone. Recording
     * the category on the row itself keeps the dashboard's category breakdown
     * accurate. The index serves the dashboard charts' filter exactly
     * (this subscriber, successful rows, a date range).
     *
     * Idempotent on purpose: the schema change is guarded and the backfill runs
     * every time, so a test can re-run it against rows it has just inserted.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('delivery_logs', 'category')) {
            Schema::table('delivery_logs', function (Blueprint $table) {
                $table->string('category')->nullable()->after('status');
                $table->index(['user_id', 'status', 'dispatched_at']);
            });
        }

        // Rows whose rule is already gone cannot be recovered and stay null ("Other").
        DB::statement('
            UPDATE delivery_logs
            SET category = alert_rules.category
            FROM alert_rules
            WHERE delivery_logs.alert_rule_id = alert_rules.id
        ');
    }

    public function down(): void
    {
        Schema::table('delivery_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status', 'dispatched_at']);
            $table->dropColumn('category');
        });
    }
};
```

- [ ] **Step 4: Model and the five write sites**

`app/Models/DeliveryLog.php`: add `'category'` to the `#[Fillable([...])]` list (after `'status'`).

Add `'category' => $rule->category->value,` directly after the `'status' => ...` line in each of the five `DeliveryLog::create([...])` calls:

- `app/Jobs/DeliverWeatherAlert.php` — the success/failure create (~line 136) **and** `logSkipped()` (~line 157).
- `app/Jobs/DeliverScheduledAlert.php` — the "rule deactivated" skipped create (~line 69) **and** the success/failure create (~line 116).
- `app/Actions/Weather/MatchWeatherAlertRules.php` — the `'suppressed'` create (~line 124).

(`$rule` is the loaded `AlertRule` in all five scopes, and `AlertRule::$category` is cast to the `AlertCategory` enum.)

- [ ] **Step 5: Run to verify it passes, plus the suites that create log rows**

Run: `tests/Feature/DeliveryLogCategoryTest.php tests/Feature/DeliverScheduledAlertTest.php tests/Feature/DeliverWeatherAlertTest.php tests/Feature/MatchWeatherAlertRulesTest.php tests/Feature/RecentAlertsWidgetTest.php`
Expected: all PASS (existing tests unmodified).

- [ ] **Step 6: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_10_03_100001_add_category_to_delivery_logs_table.php app/Models/DeliveryLog.php app/Jobs/DeliverWeatherAlert.php app/Jobs/DeliverScheduledAlert.php app/Actions/Weather/MatchWeatherAlertRules.php tests/Feature/DeliveryLogCategoryTest.php
git commit -m "Record the alert category on every delivery log row

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `GetAlertDeliveryStats`

**Files:**
- Create: `app/Actions/Dashboard/GetAlertDeliveryStats.php`
- Test: `tests/Feature/GetAlertDeliveryStatsTest.php`

**Interfaces:**
- Consumes: `delivery_logs.category` (Task 1), `Location.timezone`.
- Produces (consumed by Task 3):
  - `GetAlertDeliveryStats::timezoneFor(User $user): string`
  - `perDay(User $user, int $days): array{labels: string[], counts: int[], total: int}` — exactly `$days` entries, oldest first, ending today in the user's timezone.
  - `byCategory(User $user, int $days): array<int, array{category: ?string, label: string, count: int}>` — categories by count desc (ties by label), `Other` (category `null`) last.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/GetAlertDeliveryStatsTest.php`:

```php
<?php

use App\Actions\Dashboard\GetAlertDeliveryStats;
use App\Models\DeliveryLog;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 18:00:00'); // 12:00 America/Denver, 18:00 UTC

    $this->user = User::factory()->create();
    $this->stats = new GetAlertDeliveryStats;
});

afterEach(function () {
    Carbon::setTestNow();
});

function logAt(User $user, string $utc, string $category = 'weather_alerts', string $status = 'success'): DeliveryLog
{
    return DeliveryLog::factory()->for($user)->create(['dispatched_at' => $utc, 'category' => $category, 'status' => $status]);
}

test('the timezone is the most recently added location\'s, falling back to UTC', function () {
    expect($this->stats->timezoneFor($this->user))->toBe('UTC');

    Location::factory()->for($this->user)->create(['timezone' => 'America/New_York', 'created_at' => now()->subDays(2)]);
    Location::factory()->for($this->user)->create(['timezone' => 'America/Denver', 'created_at' => now()->subDay()]);

    expect($this->stats->timezoneFor($this->user))->toBe('America/Denver');
});

test('a missing or invalid stored timezone falls back to UTC', function () {
    Location::factory()->for($this->user)->create(['timezone' => null]);
    expect($this->stats->timezoneFor($this->user))->toBe('UTC');

    Location::factory()->for($this->user)->create(['timezone' => 'Mars/Olympus_Mons', 'created_at' => now()->addMinute()]);
    expect($this->stats->timezoneFor($this->user))->toBe('UTC');
});

test('per-day returns exactly the requested days, zero-filled, oldest first, ending today', function () {
    logAt($this->user, '2026-10-02 10:00:00');
    logAt($this->user, '2026-10-02 11:00:00');
    logAt($this->user, '2026-09-30 10:00:00');

    $result = $this->stats->perDay($this->user, 7);

    expect($result['labels'])->toBe(['Sep 26', 'Sep 27', 'Sep 28', 'Sep 29', 'Sep 30', 'Oct 1', 'Oct 2'])
        ->and($result['counts'])->toBe([0, 0, 0, 0, 1, 0, 2])
        ->and($result['total'])->toBe(3);
});

test('only this subscriber\'s successful deliveries are counted', function () {
    logAt($this->user, '2026-10-02 10:00:00');
    logAt($this->user, '2026-10-02 10:00:00', status: 'failure');
    logAt($this->user, '2026-10-02 10:00:00', status: 'suppressed');
    logAt($this->user, '2026-10-02 10:00:00', status: 'skipped');
    logAt(User::factory()->create(), '2026-10-02 10:00:00');

    expect($this->stats->perDay($this->user, 7)['total'])->toBe(1)
        ->and($this->stats->byCategory($this->user, 7))->toHaveCount(1);
});

test('rows older than the range are excluded, and the range boundary is the start of the first local day', function () {
    Location::factory()->for($this->user)->create(['timezone' => 'America/Denver']);
    // Range of 7 days in Denver starts 2026-09-26 00:00 Denver = 2026-09-26 06:00 UTC.
    logAt($this->user, '2026-09-26 05:59:59'); // 2026-09-25 23:59:59 Denver: outside
    logAt($this->user, '2026-09-26 06:00:00'); // 2026-09-26 00:00:00 Denver: inside

    $result = $this->stats->perDay($this->user, 7);

    expect($result['total'])->toBe(1)->and($result['counts'][0])->toBe(1);
});

test('a delivery just after midnight UTC counts on the previous local day for a Denver subscriber', function () {
    Location::factory()->for($this->user)->create(['timezone' => 'America/Denver']);
    logAt($this->user, '2026-10-02 02:00:00'); // 2026-10-01 20:00 Denver

    $result = $this->stats->perDay($this->user, 7);

    expect($result['counts'][5])->toBe(1)   // Oct 1
        ->and($result['counts'][6])->toBe(0); // Oct 2
});

test('the same delivery counts on the UTC day for a subscriber with no location', function () {
    logAt($this->user, '2026-10-02 02:00:00');

    $result = $this->stats->perDay($this->user, 7);

    expect($result['counts'][6])->toBe(1)->and($result['counts'][5])->toBe(0);
});

test('by-category groups, orders by count then label, and puts Other last', function () {
    foreach (range(1, 3) as $i) { logAt($this->user, '2026-10-02 10:00:00', 'weather_alerts'); }
    foreach (range(1, 2) as $i) { logAt($this->user, '2026-10-02 10:00:00', 'threshold'); }
    foreach (range(1, 2) as $i) { logAt($this->user, '2026-10-02 10:00:00', 'daily_summary'); }
    logAt($this->user, '2026-10-02 10:00:00', 'lightning');
    DeliveryLog::factory()->for($this->user)->create(['dispatched_at' => '2026-10-02 10:00:00', 'category' => null]);
    DeliveryLog::factory()->for($this->user)->create(['dispatched_at' => '2026-10-02 10:00:00', 'category' => 'forecast_discussion']); // a removed category: counts as Other

    expect($this->stats->byCategory($this->user, 7))->toBe([
        ['category' => 'weather_alerts', 'label' => 'Weather Alerts', 'count' => 3],
        ['category' => 'daily_summary', 'label' => 'Daily Weather Summary', 'count' => 2],
        ['category' => 'threshold', 'label' => 'Threshold Alerts', 'count' => 2],
        ['category' => 'lightning', 'label' => 'Lightning Alerts', 'count' => 1],
        ['category' => null, 'label' => 'Other', 'count' => 2],
    ]);
});

test('by-category is empty when there is nothing in the range, and honours the range', function () {
    expect($this->stats->byCategory($this->user, 30))->toBe([]);

    logAt($this->user, '2026-09-01 10:00:00'); // 31 days ago: outside 30, inside 90

    expect($this->stats->byCategory($this->user, 30))->toBe([])
        ->and($this->stats->byCategory($this->user, 90))->toHaveCount(1);
});
```

(Category labels come from `AlertCategory::label()`; if the enum's labels differ from the strings above, use the enum's exact values.)

- [ ] **Step 2: Run to verify it fails**

Run: `tests/Feature/GetAlertDeliveryStatsTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `app/Actions/Dashboard/GetAlertDeliveryStats.php`**

```php
<?php

namespace App\Actions\Dashboard;

use App\Enums\AlertCategory;
use App\Models\DeliveryLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The numbers behind the subscriber dashboard's two alert charts. "An alert
 * sent" is a successful delivery: one delivery_logs row with status 'success'
 * (one per channel); failures, quiet-hours suppression and skipped rows are
 * excluded.
 *
 * Days are bucketed in the timezone of the subscriber's most recently added
 * Location (users have no timezone of their own), falling back to UTC, so an
 * evening alert lands on the right local day. dispatched_at is stored as a
 * naive UTC timestamp, so the bucket expression reinterprets it as UTC and then
 * converts to the subscriber's zone.
 */
class GetAlertDeliveryStats
{
    private const OTHER_LABEL = 'Other';

    public function timezoneFor(User $user): string
    {
        $timezone = $user->locations()->latest()->latest('id')->value('timezone');

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }

    /**
     * @return array{labels: array<int, string>, counts: array<int, int>, total: int}
     */
    public function perDay(User $user, int $days): array
    {
        $timezone = $this->timezoneFor($user);
        $start = $this->rangeStart($timezone, $days);

        $perDay = $this->successful($user, $start)
            ->selectRaw("(dispatched_at AT TIME ZONE 'UTC' AT TIME ZONE ?)::date as day, count(*) as total", [$timezone])
            ->groupBy('day')
            ->pluck('total', 'day');

        $labels = [];
        $counts = [];

        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i);
            $labels[] = $day->format('M j');
            $counts[] = (int) ($perDay[$day->toDateString()] ?? 0);
        }

        return ['labels' => $labels, 'counts' => $counts, 'total' => array_sum($counts)];
    }

    /**
     * @return array<int, array{category: ?string, label: string, count: int}>
     */
    public function byCategory(User $user, int $days): array
    {
        $start = $this->rangeStart($this->timezoneFor($user), $days);

        $counts = [];
        $other = 0;

        $this->successful($user, $start)
            ->selectRaw('category, count(*) as total')
            ->groupBy('category')
            ->get()
            ->each(function ($row) use (&$counts, &$other): void {
                $category = AlertCategory::tryFrom((string) $row->category);

                if ($category === null) {
                    $other += (int) $row->total;

                    return;
                }

                $counts[$category->value] = (int) $row->total;
            });

        $slices = collect($counts)
            ->map(fn (int $count, string $value): array => [
                'category' => $value,
                'label' => AlertCategory::from($value)->label(),
                'count' => $count,
            ])
            ->sort(fn (array $a, array $b): int => [$b['count'], $a['label']] <=> [$a['count'], $b['label']])
            ->values()
            ->all();

        if ($other > 0) {
            $slices[] = ['category' => null, 'label' => self::OTHER_LABEL, 'count' => $other];
        }

        return $slices;
    }

    private function rangeStart(string $timezone, int $days): Carbon
    {
        return Carbon::now($timezone)->startOfDay()->subDays($days - 1);
    }

    /**
     * @return Builder<DeliveryLog>
     */
    private function successful(User $user, Carbon $start): Builder
    {
        return DeliveryLog::query()
            ->where('user_id', $user->id)
            ->where('status', 'success')
            ->where('dispatched_at', '>=', $start->copy()->setTimezone(config('app.timezone')));
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `tests/Feature/GetAlertDeliveryStatsTest.php`
Expected: all PASS. (If `groupBy('day')` on the alias is rejected by this PostgreSQL/Laravel combination, group by the full expression with `groupByRaw("(dispatched_at AT TIME ZONE 'UTC' AT TIME ZONE ?)::date", [$timezone])` instead — keep the tests unchanged.)

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Dashboard/GetAlertDeliveryStats.php tests/Feature/GetAlertDeliveryStatsTest.php
git commit -m "Add GetAlertDeliveryStats for the dashboard alert charts

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: The two chart widgets on the dashboard

**Files:**
- Create: `app/Filament/Dashboard/Widgets/Concerns/HasDeliveryChartRange.php`, `app/Filament/Dashboard/Widgets/AlertsPerDayChart.php`, `app/Filament/Dashboard/Widgets/AlertsByCategoryChart.php`
- Modify: `app/Providers/Filament/DashboardPanelProvider.php`
- Test: `tests/Feature/AlertChartWidgetsTest.php`

**Interfaces:**
- Consumes: `GetAlertDeliveryStats::perDay()/byCategory()` (Task 2).
- Produces: `AlertsPerDayChart`, `AlertsByCategoryChart` (Filament `ChartWidget`s), `AlertsByCategoryChart::COLORS`.

Read `.ai/rules/filament.md` before editing anything under `app/Filament`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AlertChartWidgetsTest.php`:

```php
<?php

use App\Filament\Dashboard\Widgets\AlertsByCategoryChart;
use App\Filament\Dashboard\Widgets\AlertsPerDayChart;
use App\Models\DeliveryLog;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Support\Carbon;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    Carbon::setTestNow('2026-10-02 18:00:00');

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Chart data is built by a protected method; read it the way the widget's own view does.
 *
 * @return array<string, mixed>
 */
function chartData(object $component): array
{
    return (fn () => $this->getData())->call($component->instance());
}

function deliver(User $user, string $category, string $utc = '2026-10-02 10:00:00', string $status = 'success'): void
{
    DeliveryLog::factory()->for($user)->create(['dispatched_at' => $utc, 'category' => $category, 'status' => $status]);
}

test('the line chart defaults to 30 days and shows only this subscriber\'s successful deliveries per day', function () {
    deliver($this->user, 'weather_alerts');
    deliver($this->user, 'threshold');
    deliver($this->user, 'weather_alerts', status: 'failure');
    deliver(User::factory()->create(), 'weather_alerts');

    $data = chartData(livewire(AlertsPerDayChart::class));

    expect($data['labels'])->toHaveCount(30)
        ->and(end($data['labels']))->toBe('Oct 2')
        ->and($data['datasets'][0]['data'])->toHaveCount(30)
        ->and(end($data['datasets'][0]['data']))->toBe(2)
        ->and(array_sum($data['datasets'][0]['data']))->toBe(2);
});

test('the line chart range filter switches between 7, 30 and 90 days, and ignores junk', function () {
    $component = livewire(AlertsPerDayChart::class);

    expect(array_keys((fn () => $this->getFilters())->call($component->instance())))->toBe(['7', '30', '90']);

    foreach (['7' => 7, '90' => 90, '30' => 30, 'junk' => 30] as $filter => $expected) {
        $component->set('filter', $filter);

        expect(chartData($component)['labels'])->toHaveCount($expected);
    }
});

test('the line chart explains an empty period, and stays quiet when there is data', function () {
    $component = livewire(AlertsPerDayChart::class);

    expect($component->instance()->getDescription())->toBe('No alerts delivered in this period.');

    deliver($this->user, 'weather_alerts');

    expect(livewire(AlertsPerDayChart::class)->instance()->getDescription())->toBeNull();
});

test('the pie chart shows the category breakdown with a fixed color per category and Other last', function () {
    deliver($this->user, 'weather_alerts');
    deliver($this->user, 'weather_alerts');
    deliver($this->user, 'daily_summary');
    DeliveryLog::factory()->for($this->user)->create(['dispatched_at' => '2026-10-02 10:00:00', 'category' => null]);

    $data = chartData(livewire(AlertsByCategoryChart::class));

    expect($data['labels'])->toBe(['Weather Alerts', 'Daily Weather Summary', 'Other'])
        ->and($data['datasets'][0]['data'])->toBe([2, 1, 1])
        ->and($data['datasets'][0]['backgroundColor'])->toBe([
            AlertsByCategoryChart::COLORS['weather_alerts'],
            AlertsByCategoryChart::COLORS['daily_summary'],
            AlertsByCategoryChart::COLORS['other'],
        ]);
});

test('every alert category has its own distinct color', function () {
    $colors = AlertsByCategoryChart::COLORS;

    foreach (App\Enums\AlertCategory::cases() as $category) {
        expect($colors)->toHaveKey($category->value);
    }
    expect($colors)->toHaveKey('other')
        ->and(count(array_unique($colors)))->toBe(count($colors));
});

test('the pie chart range filter works and an empty period is explained', function () {
    expect(livewire(AlertsByCategoryChart::class)->instance()->getDescription())->toBe('No alerts delivered in this period.');

    deliver($this->user, 'weather_alerts', '2026-09-01 10:00:00'); // 31 days ago

    $thirty = livewire(AlertsByCategoryChart::class);
    expect(chartData($thirty)['labels'])->toBe([]);

    $ninety = livewire(AlertsByCategoryChart::class)->set('filter', '90');
    expect(chartData($ninety)['labels'])->toBe(['Weather Alerts'])
        ->and($ninety->instance()->getDescription())->toBeNull();
});

test('the charts do not poll', function () {
    foreach ([AlertsPerDayChart::class, AlertsByCategoryChart::class] as $widget) {
        expect((fn () => $this->getPollingInterval())->call(livewire($widget)->instance()))->toBeNull();
    }
});

test('both charts appear on the subscriber dashboard', function () {
    livewire(Dashboard::class)
        ->assertSeeLivewire(AlertsPerDayChart::class)
        ->assertSeeLivewire(AlertsByCategoryChart::class);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `tests/Feature/AlertChartWidgetsTest.php`
Expected: FAIL — widget classes not found.

- [ ] **Step 3: The shared range concern**

`app/Filament/Dashboard/Widgets/Concerns/HasDeliveryChartRange.php`:

```php
<?php

namespace App\Filament\Dashboard\Widgets\Concerns;

/**
 * The 7/30/90-day range both alert charts share, driven by ChartWidget's
 * built-in filter dropdown. Anything that is not one of the three offered
 * values (a stale or tampered filter) falls back to 30 days.
 */
trait HasDeliveryChartRange
{
    /**
     * @return array<string, string>
     */
    protected function getFilters(): ?array
    {
        return [
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
        ];
    }

    protected function rangeDays(): int
    {
        return in_array($this->filter, ['7', '30', '90'], true) ? (int) $this->filter : 30;
    }

    protected function emptyPeriodMessage(): string
    {
        return 'No alerts delivered in this period.';
    }
}
```

- [ ] **Step 4: The line chart**

`app/Filament/Dashboard/Widgets/AlertsPerDayChart.php`:

```php
<?php

namespace App\Filament\Dashboard\Widgets;

use App\Actions\Dashboard\GetAlertDeliveryStats;
use App\Filament\Dashboard\Widgets\Concerns\HasDeliveryChartRange;
use Filament\Widgets\ChartWidget;

/**
 * Successful deliveries per day over the chosen range, in the subscriber's
 * own day boundaries (see GetAlertDeliveryStats). Every day in the range is
 * plotted, quiet days as 0.
 */
class AlertsPerDayChart extends ChartWidget
{
    use HasDeliveryChartRange;

    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 1;

    protected ?string $heading = 'Alerts delivered per day';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '260px';

    public ?string $filter = '30';

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $stats = app(GetAlertDeliveryStats::class)->perDay(auth()->user(), $this->rangeDays());

        return [
            'datasets' => [[
                'label' => 'Alerts delivered',
                'data' => $stats['counts'],
                'borderColor' => '#dc2626',
                'backgroundColor' => 'rgba(220, 38, 38, 0.1)',
                'fill' => true,
                'tension' => 0.3,
                'pointRadius' => 3,
            ]],
            'labels' => $stats['labels'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }

    public function getDescription(): ?string
    {
        return array_sum($this->getCachedData()['datasets'][0]['data'] ?? []) === 0 ? $this->emptyPeriodMessage() : null;
    }
}
```

- [ ] **Step 5: The pie chart**

`app/Filament/Dashboard/Widgets/AlertsByCategoryChart.php`:

```php
<?php

namespace App\Filament\Dashboard\Widgets;

use App\Actions\Dashboard\GetAlertDeliveryStats;
use App\Filament\Dashboard\Widgets\Concerns\HasDeliveryChartRange;
use Filament\Widgets\ChartWidget;

/**
 * Successful deliveries by alert category over the chosen range. A category
 * keeps one fixed color across loads and filters; deliveries with no recorded
 * (or a no-longer-existing) category are grouped as "Other".
 */
class AlertsByCategoryChart extends ChartWidget
{
    use HasDeliveryChartRange;

    /**
     * @var array<string, string>
     */
    public const COLORS = [
        'weather_alerts' => '#dc2626',
        'spc_wpc' => '#f97316',
        'daily_summary' => '#3b82f6',
        'threshold' => '#8b5cf6',
        'lightning' => '#eab308',
        'other' => '#9ca3af',
    ];

    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 1;

    protected ?string $heading = 'Alerts delivered by category';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '260px';

    public ?string $filter = '30';

    protected function getType(): string
    {
        return 'pie';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $slices = app(GetAlertDeliveryStats::class)->byCategory(auth()->user(), $this->rangeDays());

        return [
            'datasets' => [[
                'label' => 'Alerts delivered',
                'data' => array_column($slices, 'count'),
                'backgroundColor' => array_map(fn (array $slice): string => self::COLORS[$slice['category'] ?? 'other'], $slices),
            ]],
            'labels' => array_column($slices, 'label'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }

    public function getDescription(): ?string
    {
        return ($this->getCachedData()['datasets'][0]['data'] ?? []) === [] ? $this->emptyPeriodMessage() : null;
    }
}
```

- [ ] **Step 6: Register on the panel**

In `app/Providers/Filament/DashboardPanelProvider.php` add `use App\Filament\Dashboard\Widgets\AlertsByCategoryChart;` and `use App\Filament\Dashboard\Widgets\AlertsPerDayChart;`, and extend the explicit widget list:

```php
            ->widgets([
                AnnouncementsWidget::class,
                RecentAlertsWidget::class,
                AlertsPerDayChart::class,
                AlertsByCategoryChart::class,
                // AccountWidget::class,
                // FilamentInfoWidget::class,
            ])
```

- [ ] **Step 7: Run to verify it passes, plus the existing dashboard widget tests**

Run: `tests/Feature/AlertChartWidgetsTest.php tests/Feature/RecentAlertsWidgetTest.php tests/Feature/GetAlertDeliveryStatsTest.php`
Expected: all PASS. If a `ChartWidget` detail differs in this Filament version (e.g. the `$filter` default is overwritten at mount, or `getPollingInterval()` is named differently), adapt only how the test reads it — the asserted behaviours (30-day default, 7/30/90 switch, no polling, description text) must stay.

- [ ] **Step 8: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Filament/Dashboard/Widgets app/Providers/Filament/DashboardPanelProvider.php tests/Feature/AlertChartWidgetsTest.php
git commit -m "Add alerts-per-day and alerts-by-category charts to the subscriber dashboard

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Post-implementation verification (controller, in a real browser)

PHP tests can't prove the charts render. After all tasks:

1. Create a throwaway local test user (Plus tier, a random password kept only in the scratchpad) with a Denver Location, and seed `delivery_logs` across the last ~40 days: successes in several categories (weather_alerts, spc_wpc, daily_summary, threshold, lightning), a few `failure`/`suppressed`/`skipped` rows, a row with a null category, a row for another user, and one success at ~02:00 UTC (the previous local day).
2. Log in (Livewire login via the in-app browser) and open `/dashboard`. Confirm: both charts render (canvas present; `Chart.getChart(canvas)` data matches what was seeded), the line chart shows 30 points with zero days, failures/suppressed/other user's rows are not counted, the 02:00-UTC row lands on the previous local day, the pie shows distinct colors with "Other" last and a bottom legend, and the layout is a two-up row under Recent Alerts.
3. Switch each widget's filter to 7 and 90 days and confirm the data and labels change; confirm there is no network polling (no repeated `livewire/update` calls every 5 s).
4. Log in as a user with no deliveries and confirm both charts render empty with "No alerts delivered in this period."
5. Check the browser console for errors; set the viewport to real size (`resize_window`) so Chart.js sizes correctly.
6. Delete the test users and seeded rows afterwards.

---

## Self-Review (run against the approved design)

**Coverage:** success-only count, labelled "Alerts delivered…" → Tasks 2–3; `category` column + backfill + index + all five write sites + `Fillable` → Task 1; timezone of the most recent Location with UTC fallback and invalid-zone handling → Task 2; 7/30/90 filter default 30 → Task 3; zero-filled line with "Oct 2" labels → Tasks 2–3; pie slices by label, largest first, ties by label, Other last, fixed colors, bottom legend → Tasks 2–3; no polling → Task 3; empty-period message → Task 3; two-up placement under Recent Alerts (`$sort`, `columnSpan 1`) → Task 3 + browser check; scoping to `auth()->user()` → Tasks 2–3 tests; all tiers → no gate; non-goals not built.

**Placeholder scan:** none. One test (the quiet-hours suppressed row) tells the implementer to copy the exact fixture helpers from `MatchWeatherAlertRulesTest.php` if they are global helpers there, while giving the full intent and working setup.

**Type consistency:** `perDay()`/`byCategory()` shapes match the widgets' use (`counts`, `labels`, `total`; `category`, `label`, `count`); `COLORS` keys equal `AlertCategory` values plus `other`; the widget `$filter` string values `'7'|'30'|'90'` match `getFilters()` keys and `rangeDays()`.
