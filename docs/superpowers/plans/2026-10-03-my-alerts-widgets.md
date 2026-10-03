# My Alerts Page Widgets Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Three widgets above the My Alerts table — an at-a-glance stats strip, a "Needs attention" list, and an "Active now at your locations" list with evidence-based coverage.

**Architecture:** Each widget is a thin Filament class over a database-only, Filament-free data action that takes the `User` (easy to test, always scoped to that user). The widgets are registered on `AlertRuleResource::getWidgets()` and `ListAlertRules::getHeaderWidgets()` in the order Stats, Needs attention, Active now. "Covered by your alerts" comes from the subscriber's `delivery_logs`, joined to the active warning through the shared VTEC event key; it never re-implements the matching rules.

**Tech Stack:** Laravel 13 / PHP 8.4, Filament v5 widgets (`StatsOverviewWidget`, `Widget`), Livewire 4, PostgreSQL/PostGIS, Pest.

**Spec:** `docs/superpowers/specs/2026-10-03-my-alerts-widgets-design.md` (binding; read it first, including its "Judgment calls", which the owner approved).

## Global Constraints

- **Scoping**: every query uses the `User` passed to the action (`auth()->user()` in the widget). The resource's `canView()` allows everyone, so scoping is the protection; no query may read another subscriber's rows.
- **Database only**: no external HTTP call from any widget or action.
- **Windows**: "last 7 days" is a rolling `now() - 7 days` window (no day bucketing); the previous window is `now() - 14 days` to `now() - 7 days`.
- **Escaping**: alert, channel and location names are subscriber-supplied; render them with Blade `{{ }}` (never `{!! !!}`). Failure rows expose only the channel label, a relative time and a count — never `error_detail`, a destination, URL or token.
- **Placement/order**: header widgets in the order Stats, Needs attention, Active now; the header grid is 2 columns: Stats `columnSpan = 'full'`, the other two `1` each. Every tier sees all three.
- **Refresh**: only Active now polls (every 60 s, `wire:poll.60s`); Stats and Needs attention set `$pollingInterval = null` / no polling. Active now's result is cached 60 s per subscriber.
- **Caps**: Needs attention 8 rows then "and N more"; Active now 6 events then "and N more"; Active now looks at the subscriber's first 25 locations that have a position (footnote when capped).
- `GetActiveAlertsForLocation::__invoke()` output and its existing tests must not change (Task 3 extracts `activeFor()`).
- Conventions: Pest feature tests; Pint (`vendor/bin/pint --dirty --format agent`) before each commit; commit directly to `main` (no push); commit messages end with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Read `.ai/rules/index.md` and the rule files covering touched paths (`filament.md` for `app/Filament/**` and `resources/views/filament/**`, `support.md`, `feature.md`) before editing. Existing Pest helper names are global: give new test helpers distinctive names (the existing `GetActiveAlertsForLocationTest.php` already defines `alertFor`, `setAlertPolygon`, `INSIDE_POLYGON`, `OUTSIDE_POLYGON`; `alertSendingTo` and `scheduledAlertUsing` exist in the deletion-guard tests).

## How to run tests (read this once)

Run Pest inside the app container (the host cannot reach the DB):

```bash
docker exec wxnotify-nextgen-front-end-1 sh -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --compact tests/Feature/SomeTest.php'
```

The full suite takes ~6 minutes and is run by the controller in the background at the end (other sessions using the same `testing` database at the same time can make it flake — rerun failures alone first). Pint runs on the host.

## File Structure

| Path | Responsibility |
| --- | --- |
| `app/Actions/Alerts/GetAlertStats.php` | the four stats + sparkline data |
| `app/Actions/Alerts/GetAlertsNeedingAttention.php` | ordered attention rows |
| `app/Actions/Alerts/GetActiveNowAtMyLocations.php` | grouped active warnings with coverage status |
| `app/Actions/Weather/GetActiveAlertsForLocation.php` | extract `activeFor()` + shared sorter (output unchanged) |
| `app/Filament/Dashboard/Resources/AlertRules/Widgets/AlertStatsWidget.php`, `NeedsAttentionWidget.php`, `ActiveNowWidget.php` | the widgets |
| `resources/views/filament/dashboard/widgets/needs-attention.blade.php`, `active-now.blade.php` | views for the two custom widgets |
| `app/Filament/Dashboard/Resources/AlertRules/AlertRuleResource.php`, `Pages/ListAlertRules.php` | registration |
| `.ai/rules/filament.md` | "My Alerts widgets" note |
| `tests/Feature/GetAlertStatsTest.php`, `GetAlertsNeedingAttentionTest.php`, `GetActiveNowAtMyLocationsTest.php`, `AlertWidgetsTest.php` | tests |

---

### Task 1: The stats strip

**Files:**
- Create: `app/Actions/Alerts/GetAlertStats.php`, `app/Filament/Dashboard/Resources/AlertRules/Widgets/AlertStatsWidget.php`
- Modify: `app/Filament/Dashboard/Resources/AlertRules/AlertRuleResource.php`, `app/Filament/Dashboard/Resources/AlertRules/Pages/ListAlertRules.php`
- Test: `tests/Feature/GetAlertStatsTest.php`, `tests/Feature/AlertWidgetsTest.php` (new; later tasks append)

**Interfaces:**
- Produces: `GetAlertStats::__invoke(User $user): array{active: int, total: int, deliveries: int, previous_deliveries: int, failures: int, held: int, daily: list<int>}` (`daily` = the last 7 days of successful deliveries, oldest first, from `GetAlertDeliveryStats::perDay($user, 7)['counts']`); `AlertStatsWidget`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/GetAlertStatsTest.php`:

```php
<?php

use App\Actions\Alerts\GetAlertStats;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-03 12:00:00');
    $this->user = User::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

function statsDelivery(User $user, string $status, string|Carbon $when): DeliveryLog
{
    return DeliveryLog::factory()->for($user)->create(['status' => $status, 'dispatched_at' => $when, 'category' => 'weather_alerts']);
}

test('active and total alert counts', function () {
    AlertRule::factory()->for($this->user)->count(3)->create(['is_active' => true]);
    AlertRule::factory()->for($this->user)->count(2)->create(['is_active' => false]);
    AlertRule::factory()->for(User::factory())->create(['is_active' => true]); // someone else's

    $stats = (new GetAlertStats)($this->user);

    expect($stats['active'])->toBe(3)->and($stats['total'])->toBe(5);
});

test('deliveries, failures and quiet-hours holds are counted over a rolling 7 days', function () {
    statsDelivery($this->user, 'success', now()->subDays(6)->subHours(23)); // inside
    statsDelivery($this->user, 'success', now()->subHour());                 // inside
    statsDelivery($this->user, 'success', now()->subDays(7)->subHour());     // just outside
    statsDelivery($this->user, 'failure', now()->subDays(2));
    statsDelivery($this->user, 'failure', now()->subDays(9));                // outside
    statsDelivery($this->user, 'suppressed', now()->subDays(3));
    statsDelivery($this->user, 'skipped', now()->subDays(3));                // not counted anywhere

    $stats = (new GetAlertStats)($this->user);

    expect($stats['deliveries'])->toBe(2)
        ->and($stats['failures'])->toBe(1)
        ->and($stats['held'])->toBe(1);
});

test('the previous 7 days are counted for the trend', function () {
    statsDelivery($this->user, 'success', now()->subDays(8));
    statsDelivery($this->user, 'success', now()->subDays(13));
    statsDelivery($this->user, 'success', now()->subDays(15)); // older than 14 days
    statsDelivery($this->user, 'success', now()->subDay());    // current window

    $stats = (new GetAlertStats)($this->user);

    expect($stats['previous_deliveries'])->toBe(2)->and($stats['deliveries'])->toBe(1);
});

test('only this subscriber\'s deliveries are counted', function () {
    statsDelivery($this->user, 'success', now()->subHour());
    statsDelivery(User::factory()->create(), 'success', now()->subHour());
    statsDelivery(User::factory()->create(), 'failure', now()->subHour());

    $stats = (new GetAlertStats)($this->user);

    expect($stats['deliveries'])->toBe(1)->and($stats['failures'])->toBe(0);
});

test('the sparkline is the last 7 days of successes, oldest first', function () {
    statsDelivery($this->user, 'success', now()->subHour());
    statsDelivery($this->user, 'success', now()->subHours(2));
    statsDelivery($this->user, 'success', now()->subDays(2));

    $daily = (new GetAlertStats)($this->user)['daily'];

    expect($daily)->toHaveCount(7)->and(array_sum($daily))->toBe(3)->and($daily[6])->toBe(2)->and($daily[4])->toBe(1);
});

test('a subscriber with nothing gets zeros', function () {
    expect((new GetAlertStats)($this->user))->toBe([
        'active' => 0, 'total' => 0, 'deliveries' => 0, 'previous_deliveries' => 0, 'failures' => 0, 'held' => 0,
        'daily' => [0, 0, 0, 0, 0, 0, 0],
    ]);
});

test('the number of queries does not depend on how much data there is', function () {
    $count = function (int $rows): int {
        DeliveryLog::query()->where('user_id', $this->user->id)->delete();
        foreach (range(1, $rows) as $i) {
            statsDelivery($this->user, 'success', now()->subHours($i));
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        (new GetAlertStats)($this->user);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(30))->toBe($count(1));
});
```

(Add `use Illuminate\Support\Facades\DB;`. The sparkline indices assume `GetAlertDeliveryStats::perDay` buckets in the user's timezone, which is UTC here because the user has no profile timezone and no locations.)

`tests/Feature/AlertWidgetsTest.php` (this task's part; later tasks append):

```php
<?php

use App\Filament\Dashboard\Resources\AlertRules\Pages\ListAlertRules;
use App\Filament\Dashboard\Resources\AlertRules\Widgets\AlertStatsWidget;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    Carbon::setTestNow('2026-10-03 12:00:00');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** The stats the widget would render, as label => value. */
function widgetStats(object $component): array
{
    return collect((fn () => $this->getStats())->call($component->instance()))
        ->mapWithKeys(fn ($stat) => [(string) $stat->getLabel() => (string) $stat->getValue()])
        ->all();
}

test('the stats strip shows active alerts, deliveries, failures and quiet-hours holds', function () {
    AlertRule::factory()->for($this->user)->count(2)->create(['is_active' => true]);
    AlertRule::factory()->for($this->user)->create(['is_active' => false]);
    DeliveryLog::factory()->for($this->user)->count(3)->create(['status' => 'success', 'dispatched_at' => now()->subHour()]);
    DeliveryLog::factory()->for($this->user)->create(['status' => 'failure', 'dispatched_at' => now()->subHour()]);
    DeliveryLog::factory()->for($this->user)->create(['status' => 'suppressed', 'dispatched_at' => now()->subHour()]);

    expect(widgetStats(livewire(AlertStatsWidget::class)))->toBe([
        'Active alerts' => '2 of 3',
        'Deliveries, last 7 days' => '3',
        'Failed deliveries, last 7 days' => '1',
        'Held for quiet hours, last 7 days' => '1',
    ]);
});

test('the failed-deliveries stat is colored danger above zero and success at zero', function () {
    $colorOf = fn () => (fn () => collect($this->getStats())->first(fn ($stat) => str_starts_with((string) $stat->getLabel(), 'Failed'))->getColor())
        ->call(livewire(AlertStatsWidget::class)->instance());

    expect($colorOf())->toBe('success');

    DeliveryLog::factory()->for($this->user)->create(['status' => 'failure', 'dispatched_at' => now()->subHour()]);

    expect($colorOf())->toBe('danger');
});

test('the deliveries trend compares with the previous 7 days', function () {
    DeliveryLog::factory()->for($this->user)->count(3)->create(['status' => 'success', 'dispatched_at' => now()->subHour()]);
    DeliveryLog::factory()->for($this->user)->create(['status' => 'success', 'dispatched_at' => now()->subDays(9)]);

    $description = (fn () => collect($this->getStats())->first(fn ($stat) => str_starts_with((string) $stat->getLabel(), 'Deliveries'))->getDescription())
        ->call(livewire(AlertStatsWidget::class)->instance());

    expect((string) $description)->toContain('2 more than the previous 7 days');
});

test('the stats strip appears on the My Alerts page, with data and without', function () {
    livewire(ListAlertRules::class)->assertSeeLivewire(AlertStatsWidget::class);

    AlertRule::factory()->for($this->user)->create();

    livewire(ListAlertRules::class)->assertSeeLivewire(AlertStatsWidget::class);
});

test('the stats widget does not poll and spans the full width', function () {
    $widget = livewire(AlertStatsWidget::class)->instance();

    expect((fn () => $this->getPollingInterval())->call($widget))->toBeNull()
        ->and((fn () => $this->columnSpan)->call($widget))->toBe('full');
});
```

(Adapt the `Stat` accessor names — `getLabel()`, `getValue()`, `getColor()`, `getDescription()` — to the installed Filament v5 `Stat` class if they differ; keep every assertion's intent. `Failed deliveries` is `danger` when `> 0`, else `success`.)

- [ ] **Step 2: Run to verify it fails**

Run: `tests/Feature/GetAlertStatsTest.php tests/Feature/AlertWidgetsTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: The action**

`app/Actions/Alerts/GetAlertStats.php`:

```php
<?php

namespace App\Actions\Alerts;

use App\Actions\Dashboard\GetAlertDeliveryStats;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\User;

/**
 * The numbers in the My Alerts stats strip: how many of the subscriber's alerts
 * are active, and what happened to their deliveries over a rolling 7 days
 * (one delivery_logs row per channel, so these are "deliveries", not alerts).
 * Database only, scoped to the given user.
 */
class GetAlertStats
{
    /**
     * @return array{active: int, total: int, deliveries: int, previous_deliveries: int, failures: int, held: int, daily: list<int>}
     */
    public function __invoke(User $user): array
    {
        $alerts = AlertRule::query()
            ->where('user_id', $user->id)
            ->selectRaw('count(*) as total, count(*) filter (where is_active) as active')
            ->first();

        $since = now()->subDays(7);
        $before = now()->subDays(14);

        $deliveries = DeliveryLog::query()
            ->where('user_id', $user->id)
            ->where('dispatched_at', '>=', $before)
            ->selectRaw(
                "count(*) filter (where status = 'success' and dispatched_at >= ?) as deliveries,
                 count(*) filter (where status = 'success' and dispatched_at < ?) as previous_deliveries,
                 count(*) filter (where status = 'failure' and dispatched_at >= ?) as failures,
                 count(*) filter (where status = 'suppressed' and dispatched_at >= ?) as held",
                [$since, $since, $since, $since],
            )
            ->first();

        return [
            'active' => (int) $alerts->active,
            'total' => (int) $alerts->total,
            'deliveries' => (int) $deliveries->deliveries,
            'previous_deliveries' => (int) $deliveries->previous_deliveries,
            'failures' => (int) $deliveries->failures,
            'held' => (int) $deliveries->held,
            'daily' => app(GetAlertDeliveryStats::class)->perDay($user, 7)['counts'],
        ];
    }
}
```

- [ ] **Step 4: The widget and registration**

`app/Filament/Dashboard/Resources/AlertRules/Widgets/AlertStatsWidget.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Widgets;

use App\Actions\Alerts\GetAlertStats;
use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The stats strip above the My Alerts table. Database only; every number comes
 * from GetAlertStats for the signed-in subscriber. canView() mirrors the
 * resource's gate (which allows everyone today): the protection is that the
 * data is always scoped to auth()->user().
 */
class AlertStatsWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return AlertRuleResource::canAccess();
    }

    /**
     * @return int|array<string, ?int>|null
     */
    protected function getColumns(): int|array|null
    {
        return ['default' => 1, 'sm' => 2, 'xl' => 4];
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $stats = app(GetAlertStats::class)(auth()->user());

        return [
            Stat::make('Active alerts', "{$stats['active']} of {$stats['total']}")
                ->color('primary'),
            Stat::make('Deliveries, last 7 days', $stats['deliveries'])
                ->description($this->trend($stats['deliveries'], $stats['previous_deliveries']))
                ->chart($stats['daily'])
                ->color('primary'),
            Stat::make('Failed deliveries, last 7 days', $stats['failures'])
                ->color($stats['failures'] > 0 ? 'danger' : 'success'),
            Stat::make('Held for quiet hours, last 7 days', $stats['held'])
                ->description('Not sent because of quiet hours')
                ->color('gray'),
        ];
    }

    private function trend(int $current, int $previous): string
    {
        $difference = $current - $previous;

        return match (true) {
            $difference > 0 => "{$difference} more than the previous 7 days",
            $difference < 0 => abs($difference).' fewer than the previous 7 days',
            default => 'Same as the previous 7 days',
        };
    }
}
```

In `AlertRuleResource` add (+ import)

```php
    public static function getWidgets(): array
    {
        return [
            AlertStatsWidget::class,
        ];
    }
```

and in `ListAlertRules` add (+ import)

```php
    protected function getHeaderWidgets(): array
    {
        return [
            AlertStatsWidget::class,
        ];
    }
```

- [ ] **Step 5: Run to verify it passes**

Run: `tests/Feature/GetAlertStatsTest.php tests/Feature/AlertWidgetsTest.php tests/Feature/AlertRuleResourceTest.php tests/Feature/GetAlertDeliveryStatsTest.php`
Expected: all PASS.

- [ ] **Step 6: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Alerts/GetAlertStats.php app/Filament/Dashboard/Resources/AlertRules/Widgets/AlertStatsWidget.php app/Filament/Dashboard/Resources/AlertRules/AlertRuleResource.php app/Filament/Dashboard/Resources/AlertRules/Pages/ListAlertRules.php tests/Feature/GetAlertStatsTest.php tests/Feature/AlertWidgetsTest.php
git commit -m "Add a stats strip above the My Alerts table

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Needs attention

**Files:**
- Create: `app/Actions/Alerts/GetAlertsNeedingAttention.php`, `app/Filament/Dashboard/Resources/AlertRules/Widgets/NeedsAttentionWidget.php`, `resources/views/filament/dashboard/widgets/needs-attention.blade.php`
- Modify: `AlertRuleResource.php`, `Pages/ListAlertRules.php` (add after `AlertStatsWidget`), `tests/Feature/AlertWidgetsTest.php` (append)
- Test: `tests/Feature/GetAlertsNeedingAttentionTest.php`

**Interfaces:**
- Consumes: Task 1's registration lists (add this widget second).
- Produces: `GetAlertsNeedingAttention::__invoke(User $user): list<array{kind: 'channel_failing'|'channel_unverified'|'location_no_position'|'alert_inactive', severity: 'danger'|'warning'|'gray', subject: string, detail: string, target: array{type: 'channels'|'location'|'alert', id: ?int}}>` — full ordered list (the widget caps it); `NeedsAttentionWidget`.
- Wording (subject is rendered bold, then a space, then detail): failing → subject "My Discord", detail "had a failed delivery 3 hours ago (4 failures in 7 days)"; unverified → "isn't verified yet, so alerts using it can't deliver (Storm warnings, Morning summary)" (up to 3 alert names, then "and N more"); no position → subject = alert name, detail "is waiting for Truck to report a position"; inactive → subject = alert name, detail "is turned off".

Read `.ai/rules/filament.md` before editing.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/GetAlertsNeedingAttentionTest.php`:

```php
<?php

use App\Actions\Alerts\GetAlertsNeedingAttention;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-10-03 12:00:00');
    $this->user = User::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

function attentionRows(User $user): array
{
    return (new GetAlertsNeedingAttention)($user);
}

/** @return array<int, string> "kind: subject" for each row, in order */
function attentionSummary(User $user): array
{
    return array_map(fn (array $row): string => "{$row['kind']}: {$row['subject']}", attentionRows($user));
}

function channelAttempt(User $user, NotificationChannel $channel, string $status, string|Carbon $when): void
{
    DeliveryLog::factory()->for($user)->create([
        'notification_channel_id' => $channel->id, 'status' => $status, 'dispatched_at' => $when, 'error_detail' => 'secret-destination https://hooks.example.com/token',
    ]);
}

function attentionAlert(User $user, array $channels = [], array $attributes = []): AlertRule
{
    $alert = AlertRule::factory()->dailySummary()->for($user)->for(Location::factory()->for($user)->create())->create($attributes);
    $alert->notificationChannels()->attach(collect($channels)->pluck('id'));

    return $alert;
}

test('a channel whose latest attempt in 7 days failed is reported with a count and a relative time', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create(['label' => 'My Discord']);
    channelAttempt($this->user, $channel, 'failure', now()->subDays(2));
    channelAttempt($this->user, $channel, 'failure', now()->subHours(3));
    channelAttempt($this->user, $channel, 'success', now()->subDays(3));

    $rows = attentionRows($this->user);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['kind' => 'channel_failing', 'severity' => 'danger', 'subject' => 'My Discord', 'target' => ['type' => 'channels', 'id' => null]])
        ->and($rows[0]['detail'])->toBe('had a failed delivery 3 hours ago (2 failures in 7 days)');
});

test('a success after the failures clears the channel; old failures do not count', function () {
    $recovered = NotificationChannel::factory()->for($this->user)->create(['label' => 'Recovered']);
    channelAttempt($this->user, $recovered, 'failure', now()->subHours(5));
    channelAttempt($this->user, $recovered, 'success', now()->subHour());

    $old = NotificationChannel::factory()->for($this->user)->create(['label' => 'Old trouble']);
    channelAttempt($this->user, $old, 'failure', now()->subDays(8));

    expect(attentionRows($this->user))->toBe([]);
});

test('skipped and suppressed attempts do not make a channel failing or healthy', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create(['label' => 'My Discord']);
    channelAttempt($this->user, $channel, 'failure', now()->subHours(6));
    channelAttempt($this->user, $channel, 'suppressed', now()->subHour());
    channelAttempt($this->user, $channel, 'skipped', now()->subMinutes(10));

    expect(attentionSummary($this->user))->toBe(['channel_failing: My Discord']);
});

test('an unverified channel used by an active alert is reported with up to three alert names', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create(['label' => 'Work email', 'verified_at' => null]);
    foreach (['Alpha', 'Bravo', 'Charlie', 'Delta'] as $name) {
        attentionAlert($this->user, [$channel], ['name' => $name]);
    }

    $rows = attentionRows($this->user);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['kind' => 'channel_unverified', 'severity' => 'warning', 'subject' => 'Work email'])
        ->and($rows[0]['detail'])->toBe("isn't verified yet, so alerts using it can't deliver (Alpha, Bravo, Charlie and 1 more)");
});

test('an unverified channel that no active alert uses is not reported', function () {
    NotificationChannel::factory()->for($this->user)->create(['verified_at' => null]);
    $used = NotificationChannel::factory()->for($this->user)->create(['verified_at' => null]);
    attentionAlert($this->user, [$used], ['is_active' => false, 'name' => 'Paused']);

    expect(array_column(attentionRows($this->user), 'kind'))->toBe(['alert_inactive']);
});

test('a channel that is both failing and unverified appears once, as failing', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create(['label' => 'Both', 'verified_at' => null]);
    attentionAlert($this->user, [$channel]);
    channelAttempt($this->user, $channel, 'failure', now()->subHour());

    expect(attentionSummary($this->user))->toBe(['channel_failing: Both']);
});

test('an active scheduled alert on a mobile location with no position is reported', function () {
    $truck = Location::factory()->mobile()->for($this->user)->create(['label' => 'Truck']);
    foreach (['daily' => AlertRule::factory()->dailySummary(), 'lightning' => AlertRule::factory()->lightning(), 'threshold' => AlertRule::factory()] as $name => $factory) {
        $factory->for($this->user)->for($truck)->create(['name' => "{$name} alert"]);
    }

    $rows = attentionRows($this->user);

    expect(array_column($rows, 'kind'))->toBe(['location_no_position', 'location_no_position', 'location_no_position'])
        ->and($rows[0])->toMatchArray(['severity' => 'warning', 'detail' => 'is waiting for Truck to report a position', 'target' => ['type' => 'location', 'id' => $truck->id]]);
});

test('a mobile location with a position, a stationary location, or an inactive alert is not a position problem', function () {
    $withFix = Location::factory()->mobile()->withFix(40.0, -105.0)->for($this->user)->create();
    $noFix = Location::factory()->mobile()->for($this->user)->create();
    AlertRule::factory()->dailySummary()->for($this->user)->for($withFix)->create();
    AlertRule::factory()->dailySummary()->for($this->user)->for(Location::factory()->for($this->user)->create())->create();
    AlertRule::factory()->dailySummary()->for($this->user)->for($noFix)->create(['is_active' => false, 'name' => 'Paused']);

    expect(attentionSummary($this->user))->toBe(['alert_inactive: Paused']);
});

test('an inactive alert is reported once, as inactive', function () {
    attentionAlert($this->user, [], ['is_active' => false, 'name' => 'Storm warnings']);

    $rows = attentionRows($this->user);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['kind' => 'alert_inactive', 'severity' => 'gray', 'subject' => 'Storm warnings', 'detail' => 'is turned off'])
        ->and($rows[0]['target']['type'])->toBe('alert');
});

test('rows are ordered failing, unverified, no position, inactive and then by name', function () {
    $failing = NotificationChannel::factory()->for($this->user)->create(['label' => 'Zed channel']);
    channelAttempt($this->user, $failing, 'failure', now()->subHour());
    $unverified = NotificationChannel::factory()->for($this->user)->create(['label' => 'Alpha email', 'verified_at' => null]);
    attentionAlert($this->user, [$unverified], ['name' => 'Uses alpha']);
    AlertRule::factory()->dailySummary()->for($this->user)->for(Location::factory()->mobile()->for($this->user)->create(['label' => 'Truck']))->create(['name' => 'Truck summary']);
    attentionAlert($this->user, [], ['is_active' => false, 'name' => 'Beta off']);
    attentionAlert($this->user, [], ['is_active' => false, 'name' => 'Alpha off']);

    expect(attentionSummary($this->user))->toBe([
        'channel_failing: Zed channel',
        'channel_unverified: Alpha email',
        'location_no_position: Truck summary',
        'alert_inactive: Alpha off',
        'alert_inactive: Beta off',
    ]);
});

test('a healthy subscriber has no rows', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create();
    attentionAlert($this->user, [$channel]);
    channelAttempt($this->user, $channel, 'success', now()->subHour());

    expect(attentionRows($this->user))->toBe([]);
});

test('another subscriber\'s problems never appear, and no row leaks a destination or error detail', function () {
    $stranger = User::factory()->create();
    $theirs = NotificationChannel::factory()->for($stranger)->create(['label' => 'Their channel', 'verified_at' => null]);
    attentionAlert($stranger, [$theirs], ['is_active' => false, 'name' => 'Their alert']);
    channelAttempt($stranger, $theirs, 'failure', now()->subHour());

    $mine = NotificationChannel::factory()->for($this->user)->create(['label' => 'Mine']);
    channelAttempt($this->user, $mine, 'failure', now()->subHour());

    $json = json_encode(attentionRows($this->user));

    expect(attentionSummary($this->user))->toBe(['channel_failing: Mine'])
        ->and($json)->not->toContain('Their')->not->toContain('secret-destination')->not->toContain('hooks.example.com');
});

test('the number of queries does not grow with the number of problems', function () {
    $count = function (int $problems): int {
        AlertRule::query()->where('user_id', $this->user->id)->delete();
        NotificationChannel::query()->where('user_id', $this->user->id)->delete();
        foreach (range(1, $problems) as $i) {
            $channel = NotificationChannel::factory()->for($this->user)->create(['verified_at' => null]);
            attentionAlert($this->user, [$channel], ['is_active' => $i % 2 === 0]);
            channelAttempt($this->user, NotificationChannel::factory()->for($this->user)->create(), 'failure', now()->subHour());
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        attentionRows($this->user);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(8))->toBe($count(1));
});
```

Append to `tests/Feature/AlertWidgetsTest.php` (add imports for `NeedsAttentionWidget`, `NotificationChannel`, `Location`):

```php
test('Needs attention shows an all-clear when nothing is wrong', function () {
    livewire(NeedsAttentionWidget::class)->assertSee('All your alerts look healthy');
});

test('Needs attention lists each problem with a link to the fix', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create(['label' => 'Work email', 'verified_at' => null]);
    $alert = AlertRule::factory()->dailySummary()->for($this->user)->for(Location::factory()->for($this->user)->create())->create(['name' => 'Morning summary']);
    $alert->notificationChannels()->attach($channel->id);
    $paused = AlertRule::factory()->dailySummary()->for($this->user)->for(Location::factory()->for($this->user)->create())->create(['name' => 'Storm warnings', 'is_active' => false]);

    livewire(NeedsAttentionWidget::class)
        ->assertSee('Work email')
        ->assertSee("isn't verified yet")
        ->assertSee('Storm warnings')
        ->assertSee('is turned off')
        ->assertSeeHtml(route('filament.dashboard.resources.alert-rules.edit', ['record' => $paused->id]))
        ->assertSeeHtml(route('filament.dashboard.resources.notification-channels.index'));
});

test('Needs attention shows at most 8 rows and counts the rest', function () {
    foreach (range(1, 11) as $i) {
        AlertRule::factory()->dailySummary()->for($this->user)->for(Location::factory()->for($this->user)->create())->create(['name' => sprintf('Paused %02d', $i), 'is_active' => false]);
    }

    livewire(NeedsAttentionWidget::class)
        ->assertSee('Paused 08')
        ->assertDontSee('Paused 09')
        ->assertSee('and 3 more');
});

test('Needs attention escapes subscriber-supplied names', function () {
    AlertRule::factory()->dailySummary()->for($this->user)->for(Location::factory()->for($this->user)->create())->create(['name' => '<script>alert(1)</script>', 'is_active' => false]);

    livewire(NeedsAttentionWidget::class)
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSee('<script>alert(1)</script>', escape: true);
});

test('Needs attention appears second on the My Alerts page', function () {
    livewire(ListAlertRules::class)
        ->assertSeeLivewire(AlertStatsWidget::class)
        ->assertSeeLivewire(NeedsAttentionWidget::class);
});
```

(Route names are the panel's generated ones — confirm with `php artisan route:list --name=alert-rules` / `--name=notification-channels` and adjust; if `assertSee(..., escape: true)` isn't the installed signature use `assertSee(e('<script>alert(1)</script>'), false)`.)

- [ ] **Step 2: Run to verify it fails**

Run: `tests/Feature/GetAlertsNeedingAttentionTest.php tests/Feature/AlertWidgetsTest.php`
Expected: the new tests FAIL (classes missing); Task 1's tests pass.

- [ ] **Step 3: The action**

`app/Actions/Alerts/GetAlertsNeedingAttention.php`:

```php
<?php

namespace App\Actions\Alerts;

use App\Enums\AlertCategory;
use App\Enums\LocationType;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Things that stop (or may stop) a subscriber's alerts from doing their job,
 * as an ordered list of rows the My Alerts "Needs attention" widget renders.
 * Database only, scoped to the given user. Rows hold plain text (the view
 * escapes it) and never an error detail, destination, URL or token.
 *
 * Order: failing channels, unverified channels, mobile locations with no
 * position, inactive alerts -- each group by name. A channel appears once
 * (failing wins over unverified); an inactive alert appears only as inactive,
 * and the position/verification checks look at active alerts only.
 */
class GetAlertsNeedingAttention
{
    private const WINDOW_DAYS = 7;

    private const SCHEDULED = [AlertCategory::DailySummary, AlertCategory::Threshold, AlertCategory::Lightning];

    /**
     * @return list<array{kind: string, severity: string, subject: string, detail: string, target: array{type: string, id: ?int}}>
     */
    public function __invoke(User $user): array
    {
        $failing = $this->failingChannels($user);

        return [
            ...$failing->values()->all(),
            ...$this->unverifiedChannels($user, $failing->keys()->all()),
            ...$this->mobileLocationsWithoutPosition($user),
            ...$this->inactiveAlerts($user),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>> keyed by channel id
     */
    private function failingChannels(User $user): Collection
    {
        $since = now()->subDays(self::WINDOW_DAYS);

        $attempts = fn () => DeliveryLog::query()
            ->where('user_id', $user->id)
            ->whereNotNull('notification_channel_id')
            ->whereIn('status', ['success', 'failure'])
            ->where('dispatched_at', '>=', $since);

        $latest = $attempts()
            ->select(['notification_channel_id', 'status', 'dispatched_at'])
            ->distinct('notification_channel_id')
            ->orderBy('notification_channel_id')->orderByDesc('dispatched_at')->orderByDesc('id')
            ->get()
            ->filter(fn (DeliveryLog $log): bool => $log->status === 'failure')
            ->keyBy('notification_channel_id');

        if ($latest->isEmpty()) {
            return collect();
        }

        $failureCounts = $attempts()
            ->where('status', 'failure')
            ->whereIn('notification_channel_id', $latest->keys())
            ->groupBy('notification_channel_id')
            ->selectRaw('notification_channel_id, count(*) as failures')
            ->pluck('failures', 'notification_channel_id');

        return NotificationChannel::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $latest->keys())
            ->orderBy('label')
            ->get()
            ->mapWithKeys(function (NotificationChannel $channel) use ($latest, $failureCounts): array {
                $failures = (int) $failureCounts[$channel->id];

                return [$channel->id => [
                    'kind' => 'channel_failing',
                    'severity' => 'danger',
                    'subject' => (string) $channel->label,
                    'detail' => 'had a failed delivery '.$latest[$channel->id]->dispatched_at->diffForHumans()
                        .' ('.$failures.' '.($failures === 1 ? 'failure' : 'failures').' in '.self::WINDOW_DAYS.' days)',
                    'target' => ['type' => 'channels', 'id' => null],
                ]];
            });
    }

    /**
     * @param  array<int, int>  $excludeChannelIds
     * @return list<array<string, mixed>>
     */
    private function unverifiedChannels(User $user, array $excludeChannelIds): array
    {
        $activeAlerts = AlertRule::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->with(['notificationChannels' => fn ($query) => $query->whereNull('verified_at')->whereNotIn('notification_channels.id', $excludeChannelIds)])
            ->orderBy('id')
            ->get();

        $alertsByChannel = [];
        $channels = [];

        foreach ($activeAlerts as $alert) {
            foreach ($alert->notificationChannels as $channel) {
                $channels[$channel->id] = $channel;
                $alertsByChannel[$channel->id][] = $alert->displayName();
            }
        }

        return collect($channels)
            ->sortBy(fn (NotificationChannel $channel): string => mb_strtolower((string) $channel->label))
            ->map(function (NotificationChannel $channel) use ($alertsByChannel): array {
                $names = $alertsByChannel[$channel->id];
                $listed = implode(', ', array_slice($names, 0, 3));
                $rest = count($names) - 3;

                return [
                    'kind' => 'channel_unverified',
                    'severity' => 'warning',
                    'subject' => (string) $channel->label,
                    'detail' => "isn't verified yet, so alerts using it can't deliver (".$listed.($rest > 0 ? " and {$rest} more" : '').')',
                    'target' => ['type' => 'channels', 'id' => null],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mobileLocationsWithoutPosition(User $user): array
    {
        return AlertRule::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereIn('category', array_map(fn (AlertCategory $category): string => $category->value, self::SCHEDULED))
            ->whereHas('location', fn ($query) => $query->where('type', LocationType::Mobile->value)->where(fn ($q) => $q->whereNull('lat')->orWhereNull('lng')))
            ->with(['location', 'products'])
            ->get()
            ->sortBy(fn (AlertRule $alert): string => mb_strtolower($alert->displayName()))
            ->map(fn (AlertRule $alert): array => [
                'kind' => 'location_no_position',
                'severity' => 'warning',
                'subject' => $alert->displayName(),
                'detail' => 'is waiting for '.$alert->location->label.' to report a position',
                'target' => ['type' => 'location', 'id' => $alert->location->id],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inactiveAlerts(User $user): array
    {
        return AlertRule::query()
            ->where('user_id', $user->id)
            ->where('is_active', false)
            ->with(['location', 'products'])
            ->get()
            ->sortBy(fn (AlertRule $alert): string => mb_strtolower($alert->displayName()))
            ->map(fn (AlertRule $alert): array => [
                'kind' => 'alert_inactive',
                'severity' => 'gray',
                'subject' => $alert->displayName(),
                'detail' => 'is turned off',
                'target' => ['type' => 'alert', 'id' => $alert->id],
            ])
            ->values()
            ->all();
    }
}
```

(Remove the unused `DB` import if Pint flags it. `distinct('notification_channel_id')` with `orderBy` is PostgreSQL's `DISTINCT ON`; if Laravel's builder doesn't emit it, use `selectRaw('distinct on (notification_channel_id) notification_channel_id, status, dispatched_at')`. The query-count test is the guard that no step queries per row; if `displayName()` triggers lazy loads, add the needed relations to the `with()` lists.)

- [ ] **Step 4b: The widget, view and registration**

`app/Filament/Dashboard/Resources/AlertRules/Widgets/NeedsAttentionWidget.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Widgets;

use App\Actions\Alerts\GetAlertsNeedingAttention;
use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use App\Filament\Dashboard\Resources\Locations\LocationResource;
use App\Filament\Dashboard\Resources\NotificationChannels\NotificationChannelResource;
use Filament\Widgets\Widget;

/**
 * "Needs attention" above the My Alerts table: alerts and channels that can't
 * currently do their job, each with a link to the fix. Database only; scoped to
 * auth()->user() inside GetAlertsNeedingAttention (canView() mirrors the
 * resource gate, which allows everyone, so that scoping is the protection).
 */
class NeedsAttentionWidget extends Widget
{
    protected string $view = 'filament.dashboard.widgets.needs-attention';

    protected int|string|array $columnSpan = 1;

    protected static bool $isLazy = false;

    private const MAX_ROWS = 8;

    public static function canView(): bool
    {
        return AlertRuleResource::canAccess();
    }

    /**
     * @return array{rows: list<array<string, mixed>>, more: int, count: int}
     */
    protected function getViewData(): array
    {
        $all = app(GetAlertsNeedingAttention::class)(auth()->user());

        return [
            'rows' => array_map(fn (array $row): array => [...$row, 'url' => $this->urlFor($row['target']), ...$this->iconFor($row['severity'])], array_slice($all, 0, self::MAX_ROWS)),
            'more' => max(0, count($all) - self::MAX_ROWS),
            'count' => count($all),
        ];
    }

    /**
     * @param  array{type: string, id: ?int}  $target
     */
    private function urlFor(array $target): string
    {
        return match ($target['type']) {
            'channels' => NotificationChannelResource::getUrl('index'),
            'location' => LocationResource::getUrl('edit', ['record' => $target['id']]),
            default => AlertRuleResource::getUrl('edit', ['record' => $target['id']]),
        };
    }

    /**
     * @return array{icon: string, icon_class: string}
     */
    private function iconFor(string $severity): array
    {
        return match ($severity) {
            'danger' => ['icon' => 'heroicon-o-x-circle', 'icon_class' => 'text-danger-600 dark:text-danger-400'],
            'warning' => ['icon' => 'heroicon-o-exclamation-triangle', 'icon_class' => 'text-warning-600 dark:text-warning-400'],
            default => ['icon' => 'heroicon-o-pause-circle', 'icon_class' => 'text-gray-500 dark:text-gray-400'],
        };
    }
}
```

`resources/views/filament/dashboard/widgets/needs-attention.blade.php`:

```blade
<x-filament-widgets::widget>
    <x-filament::section compact>
        <x-slot name="heading">
            Needs attention
            @if ($count > 0)
                <x-filament::badge color="warning" size="sm" class="ml-2 inline-flex">{{ $count }}</x-filament::badge>
            @endif
        </x-slot>

        @if ($rows === [])
            <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-success-600 dark:text-success-400" />
                <span>All your alerts look healthy.</span>
            </div>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($rows as $row)
                    <li class="flex items-start gap-3 py-2">
                        <x-filament::icon :icon="$row['icon']" class="mt-0.5 h-5 w-5 shrink-0 {{ $row['icon_class'] }}" />
                        <div class="min-w-0 flex-1 text-sm">
                            <span class="font-medium text-gray-950 dark:text-white">{{ $row['subject'] }}</span>
                            <span class="text-gray-600 dark:text-gray-400">{{ $row['detail'] }}</span>
                        </div>
                        <a href="{{ $row['url'] }}" class="shrink-0 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">Fix</a>
                    </li>
                @endforeach
            </ul>

            @if ($more > 0)
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">and {{ $more }} more</p>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
```

Register: add `NeedsAttentionWidget::class` **after** `AlertStatsWidget::class` in both `AlertRuleResource::getWidgets()` and `ListAlertRules::getHeaderWidgets()`.

- [ ] **Step 5: Run to verify it passes**

Run: `tests/Feature/GetAlertsNeedingAttentionTest.php tests/Feature/AlertWidgetsTest.php tests/Feature/AlertRuleResourceTest.php`
Expected: all PASS.

- [ ] **Step 6: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Alerts/GetAlertsNeedingAttention.php app/Filament/Dashboard/Resources/AlertRules/Widgets/NeedsAttentionWidget.php resources/views/filament/dashboard/widgets/needs-attention.blade.php app/Filament/Dashboard/Resources/AlertRules/AlertRuleResource.php app/Filament/Dashboard/Resources/AlertRules/Pages/ListAlertRules.php tests/Feature/GetAlertsNeedingAttentionTest.php tests/Feature/AlertWidgetsTest.php
git commit -m "Add a Needs attention widget above the My Alerts table

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Active now at your locations

**Files:**
- Create: `app/Actions/Alerts/GetActiveNowAtMyLocations.php`, `app/Filament/Dashboard/Resources/AlertRules/Widgets/ActiveNowWidget.php`, `resources/views/filament/dashboard/widgets/active-now.blade.php`
- Modify: `app/Actions/Weather/GetActiveAlertsForLocation.php` (extract `activeFor()` and a shared sorter), `AlertRuleResource.php`, `Pages/ListAlertRules.php` (add last), `tests/Feature/AlertWidgetsTest.php` (append), `.ai/rules/filament.md`
- Test: `tests/Feature/GetActiveNowAtMyLocationsTest.php`; existing `tests/Feature/GetActiveAlertsForLocationTest.php` must pass unchanged

**Interfaces:**
- Consumes: `GetActiveAlertsForLocation` (refactored), `ActiveWwa::vtecKeyFor()` / `IngestedProduct` VTEC columns, `DeliveryLog.ingested_product_id`.
- Produces:
  - `GetActiveAlertsForLocation::activeFor(Location $location, bool $includeTest = false): Collection<int, ActiveWwa>` (sorted: family rank, then expiry, then id; each with `product` loaded) and `public static function sorted(Collection $wwas): Collection` (the one comparator); `__invoke()` returns exactly what it did.
  - `GetActiveNowAtMyLocations::__invoke(User $user): array{events: list<array{key: int, event: string, family: ?string, locations: list<string>, expires: ?string, status: 'alerted'|'held'|'failed'|'none', alerted_via: list<string>, alerted_at: ?string}>, more: int, has_locations: bool, capped: bool}`.
  - `ActiveNowWidget`.

Read `.ai/rules/filament.md` before editing.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/GetActiveNowAtMyLocationsTest.php`:

```php
<?php

use App\Actions\Alerts\GetActiveNowAtMyLocations;
use App\Actions\Weather\GetActiveAlertsForLocation;
use App\Models\ActiveWwa;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Carbon::setTestNow('2026-10-03 15:00:00');
    $this->user = User::factory()->create();
    $this->home = Location::factory()->for($this->user)->create([
        'label' => 'Home', 'lat' => 40.0, 'lng' => -105.0, 'timezone' => 'America/Denver',
        'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** An active warning covering the given UGC zone (polygon-less), with a matching ingested product. */
function activeNowWarning(string $name = 'Tornado Warning', string $family = 'warning', string $zone = 'COZ040', array $attributes = []): ActiveWwa
{
    $vtec = ['vtec_office' => 'KBOU', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W', 'vtec_event_number' => str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT), 'vtec_year' => 2026];

    return ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => $name, 'family' => $family])->id,
        'ugc_codes' => [$zone],
        'expires' => now()->addHour(),
        ...$vtec,
        ...$attributes,
    ]);
}

/** Records a delivery of the warning's event to the user. */
function activeNowDelivery(User $user, ActiveWwa $wwa, string $status, ?AlertRule $rule = null, string|Carbon|null $when = null): DeliveryLog
{
    $ingested = IngestedProduct::factory()->create([
        'vtec_office' => $wwa->vtec_office, 'vtec_phenomenon' => $wwa->vtec_phenomenon, 'vtec_significance' => $wwa->vtec_significance,
        'vtec_event_number' => $wwa->vtec_event_number, 'vtec_year' => $wwa->vtec_year, 'is_test' => false,
    ]);

    return DeliveryLog::factory()->for($user)->create([
        'ingested_product_id' => $ingested->id, 'alert_rule_id' => $rule?->id, 'status' => $status, 'dispatched_at' => $when ?? now()->subMinutes(30),
    ]);
}

function activeNowRule(User $user, string $name): AlertRule
{
    return AlertRule::factory()->for($user)->create(['name' => $name, 'category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => null]);
}

test('an active warning at a location is listed with the location and its expiry in that location\'s timezone', function () {
    activeNowWarning('Tornado Warning');

    $result = (new GetActiveNowAtMyLocations)($this->user);

    expect($result['has_locations'])->toBeTrue()->and($result['events'])->toHaveCount(1)
        ->and($result['events'][0])->toMatchArray(['event' => 'Tornado Warning', 'family' => 'warning', 'locations' => ['Home'], 'status' => 'none'])
        ->and($result['events'][0]['expires'])->toBe('2026-10-03T10:00:00-06:00');
});

test('one warning covering two locations is a single event naming both', function () {
    Location::factory()->for($this->user)->create(['label' => 'Cabin', 'lat' => 40.1, 'lng' => -105.1, 'timezone' => 'America/Denver', 'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014']);
    activeNowWarning();

    $events = (new GetActiveNowAtMyLocations)($this->user)['events'];

    expect($events)->toHaveCount(1)->and($events[0]['locations'])->toBe(['Cabin', 'Home']);
});

test('status is alerted when a delivery succeeded, naming the alert and time', function () {
    $wwa = activeNowWarning();
    activeNowDelivery($this->user, $wwa, 'success', activeNowRule($this->user, 'Storm warnings'), now()->subMinutes(45));

    $event = (new GetActiveNowAtMyLocations)($this->user)['events'][0];

    expect($event['status'])->toBe('alerted')->and($event['alerted_via'])->toBe(['Storm warnings'])
        ->and($event['alerted_at'])->toBe('2026-10-03T08:15:00-06:00');
});

test('status precedence is alerted over held over failed over none', function () {
    $held = activeNowWarning('Held one');
    activeNowDelivery($this->user, $held, 'suppressed');
    $failed = activeNowWarning('Failed one');
    activeNowDelivery($this->user, $failed, 'failure');
    $mixed = activeNowWarning('Mixed one');
    activeNowDelivery($this->user, $mixed, 'failure');
    activeNowDelivery($this->user, $mixed, 'suppressed');
    activeNowDelivery($this->user, $mixed, 'success', activeNowRule($this->user, 'Rule'));
    activeNowWarning('Silent one');

    $byEvent = collect((new GetActiveNowAtMyLocations)($this->user)['events'])->pluck('status', 'event');

    expect($byEvent['Held one'])->toBe('held')->and($byEvent['Failed one'])->toBe('failed')
        ->and($byEvent['Mixed one'])->toBe('alerted')->and($byEvent['Silent one'])->toBe('none');
});

test('only this subscriber\'s deliveries count as coverage', function () {
    $wwa = activeNowWarning();
    activeNowDelivery(User::factory()->create(), $wwa, 'success');

    expect((new GetActiveNowAtMyLocations)($this->user)['events'][0]['status'])->toBe('none');
});

test('a delivery for a different event does not cover this one', function () {
    $wwa = activeNowWarning('Tornado Warning', attributes: ['vtec_event_number' => '0001']);
    $other = activeNowWarning('Other event', attributes: ['vtec_event_number' => '0002', 'ugc_codes' => ['COZ999']]);
    activeNowDelivery($this->user, $other, 'success');

    expect((new GetActiveNowAtMyLocations)($this->user)['events'][0]['status'])->toBe('none');
});

test('test warnings and locations without a position are ignored', function () {
    activeNowWarning('Test warning', attributes: ['is_test' => true]);
    Location::factory()->mobile()->for($this->user)->create(['label' => 'Truck']);
    $result = (new GetActiveNowAtMyLocations)($this->user);

    expect($result['events'])->toBe([]);
});

test('events are sorted by family then expiry and capped at 6 with a count of the rest', function () {
    foreach (range(1, 5) as $i) {
        activeNowWarning("Advisory {$i}", 'advisory', attributes: ['expires' => now()->addHours($i)]);
    }
    activeNowWarning('Late warning', 'warning', attributes: ['expires' => now()->addHours(9)]);
    activeNowWarning('Early warning', 'warning', attributes: ['expires' => now()->addHours(2)]);
    activeNowWarning('A watch', 'watch');

    $result = (new GetActiveNowAtMyLocations)($this->user);

    expect(collect($result['events'])->pluck('event')->all())->toBe(['Early warning', 'Late warning', 'A watch', 'Advisory 1', 'Advisory 2', 'Advisory 3'])
        ->and($result['more'])->toBe(2);
});

test('the result is cached for 60 seconds per subscriber', function () {
    activeNowWarning('First');
    (new GetActiveNowAtMyLocations)($this->user);

    activeNowWarning('Second');
    expect(collect((new GetActiveNowAtMyLocations)($this->user)['events'])->pluck('event')->all())->toBe(['First']);

    Carbon::setTestNow(now()->addSeconds(61));
    expect(collect((new GetActiveNowAtMyLocations)($this->user)['events'])->pluck('event')->sort()->values()->all())->toBe(['First', 'Second']);
});

test('only the first 25 locations with a position are looked at, and the result says so', function () {
    Location::factory()->for($this->user)->count(25)->create(['lat' => 41.0, 'lng' => -100.0, 'forecast_zone_ugc' => 'COZ777', 'county_ugc' => 'COC777']);

    expect((new GetActiveNowAtMyLocations)($this->user)['capped'])->toBeTrue();

    Cache::flush();
    Location::query()->where('user_id', $this->user->id)->where('label', '!=', 'Home')->limit(5)->delete();

    expect((new GetActiveNowAtMyLocations)($this->user)['capped'])->toBeFalse();
});

test('a subscriber with no locations says so', function () {
    $loner = User::factory()->create();

    expect((new GetActiveNowAtMyLocations)($loner))->toBe(['events' => [], 'more' => 0, 'has_locations' => false, 'capped' => false]);
});

test('extracting activeFor() left GetActiveAlertsForLocation\'s output unchanged', function () {
    activeNowWarning('Tornado Warning', attributes: ['headline' => 'Tornado Warning issued']);

    $alerts = (new GetActiveAlertsForLocation)($this->home);

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0])->toBe([
            'event' => 'Tornado Warning', 'headline' => 'Tornado Warning issued',
            'expires' => '2026-10-03T10:00:00-06:00', 'family' => 'warning',
        ]);
    expect((new GetActiveAlertsForLocation)->activeFor($this->home)->pluck('id')->all())->toHaveCount(1);
});
```

Append to `tests/Feature/AlertWidgetsTest.php` (add imports `ActiveNowWidget`, `ActiveWwa`, `Product`):

```php
test('Active now invites a subscriber with no locations to add one', function () {
    livewire(ActiveNowWidget::class)->assertSee('Add a location to see active weather alerts here');
});

test('Active now says so when nothing is active at the subscriber\'s locations', function () {
    Location::factory()->for($this->user)->create(['lat' => 40.0, 'lng' => -105.0, 'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014']);

    livewire(ActiveNowWidget::class)->assertSee('No active weather alerts at your locations');
});

test('Active now shows each warning with where it applies and whether an alert was sent', function () {
    Location::factory()->for($this->user)->create(['label' => 'Home', 'lat' => 40.0, 'lng' => -105.0, 'timezone' => 'America/Denver', 'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014']);
    ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => 'Tornado Warning', 'family' => 'warning'])->id,
        'ugc_codes' => ['COZ040'], 'expires' => now()->addHour(),
    ]);

    livewire(ActiveNowWidget::class)
        ->assertSee('Tornado Warning')
        ->assertSee('Home')
        ->assertSee('None of your alerts matched this warning')
        ->assertSeeHtml('wire:poll.60s');
});

test('Active now escapes subscriber-supplied location names', function () {
    Location::factory()->for($this->user)->create(['label' => '<script>alert(1)</script>', 'lat' => 40.0, 'lng' => -105.0, 'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014']);
    ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => 'Tornado Warning', 'family' => 'warning'])->id,
        'ugc_codes' => ['COZ040'], 'expires' => now()->addHour(),
    ]);

    livewire(ActiveNowWidget::class)->assertDontSeeHtml('<script>alert(1)</script>');
});

test('all three widgets appear on the My Alerts page in order', function () {
    livewire(ListAlertRules::class)
        ->assertSeeLivewire(AlertStatsWidget::class)
        ->assertSeeLivewire(NeedsAttentionWidget::class)
        ->assertSeeLivewire(ActiveNowWidget::class);
});
```

(The cache test relies on the `array` cache store in the test environment; the 25-location test creates 26 locations in total — Home plus 25 — so it is over the cap by one; adjust the counts if `capped` should only flip when more than 25 have a position, and keep the spec's behavior: "first 25 … footnote when capped". The `IngestedProduct::factory()` call must tolerate the VTEC overrides and not collide on unique columns — check its definition.)

- [ ] **Step 2: Run to verify it fails**

Run: `tests/Feature/GetActiveNowAtMyLocationsTest.php tests/Feature/AlertWidgetsTest.php`
Expected: the new tests FAIL.

- [ ] **Step 3: Extract `activeFor()` (behavior unchanged)**

In `app/Actions/Weather/GetActiveAlertsForLocation.php`: move the whole `ActiveWwa::query()…->get()` chain and the sorting into

```php
    /**
     * @return Collection<int, ActiveWwa>  with product loaded, sorted by family rank, expiry, id
     */
    public function activeFor(Location $location, bool $includeTest = false): Collection
    {
        if (! $location->hasFix()) {
            return new Collection;
        }

        // ... the existing $zones/$lng/$lat query exactly as it is today, ending in ->get()

        return self::sorted($alerts);
    }

    /**
     * @param  Collection<int, ActiveWwa>  $wwas
     * @return Collection<int, ActiveWwa>
     */
    public static function sorted(Collection $wwas): Collection
    {
        return $wwas->sortBy([
            fn (ActiveWwa $a, ActiveWwa $b): int => self::rankOf($a) <=> self::rankOf($b),
            fn (ActiveWwa $a, ActiveWwa $b): int => ($a->expires?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->expires?->getTimestamp() ?? PHP_INT_MAX),
            fn (ActiveWwa $a, ActiveWwa $b): int => $a->id <=> $b->id,
        ])->values();
    }
```

and make `__invoke()` call `activeFor()` and keep only the timezone computation and the `->map(...)->values()->all()` it has today. Make `rank()` a `private static function rankOf(ActiveWwa $wwa): int`. Run `tests/Feature/GetActiveAlertsForLocationTest.php` first: it must stay green untouched.

- [ ] **Step 4: The action**

`app/Actions/Alerts/GetActiveNowAtMyLocations.php`:

```php
<?php

namespace App\Actions\Alerts;

use App\Actions\Weather\GetActiveAlertsForLocation;
use App\Models\ActiveWwa;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\User;
use App\Support\Timezones;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The warnings/watches/advisories in effect at any of a subscriber's locations,
 * grouped by event, each with whether the subscriber's own alerts told them.
 *
 * Coverage is evidence, not prediction: it comes from the subscriber's
 * delivery_logs rows for the same VTEC event (the five vtec_* columns an
 * IngestedProduct shares with ActiveWwa::vtecKeyFor(), non-test only), so it
 * reuses the real matching result instead of duplicating MatchWeatherAlertRules.
 * A warning issued before an alert existed therefore reads "none". Precedence
 * per event: alerted, held (quiet hours), failed, none.
 *
 * Database only; cached 60 s per subscriber; looks at the first 25 locations
 * with a position and shows the first 6 events.
 */
class GetActiveNowAtMyLocations
{
    private const MAX_LOCATIONS = 25;

    private const MAX_EVENTS = 6;

    private const CACHE_SECONDS = 60;

    /**
     * @return array{events: list<array<string, mixed>>, more: int, has_locations: bool, capped: bool}
     */
    public function __invoke(User $user): array
    {
        return Cache::remember(
            "my-alerts-active-now:{$user->id}",
            now()->addSeconds(self::CACHE_SECONDS),
            fn (): array => $this->compute($user),
        );
    }

    /**
     * @return array{events: list<array<string, mixed>>, more: int, has_locations: bool, capped: bool}
     */
    private function compute(User $user): array
    {
        $withPosition = $user->locations()->orderBy('id')->get()->filter(fn (Location $location): bool => $location->hasFix())->values();

        if ($withPosition->isEmpty()) {
            return ['events' => [], 'more' => 0, 'has_locations' => $user->locations()->exists(), 'capped' => false];
        }

        $capped = $withPosition->count() > self::MAX_LOCATIONS;
        $lookup = app(GetActiveAlertsForLocation::class);

        /** @var array<int, array{wwa: ActiveWwa, locations: list<Location>}> $grouped */
        $grouped = [];

        foreach ($withPosition->take(self::MAX_LOCATIONS) as $location) {
            foreach ($lookup->activeFor($location) as $wwa) {
                $grouped[$wwa->id]['wwa'] ??= $wwa;
                $grouped[$wwa->id]['locations'][] = $location;
            }
        }

        $sorted = GetActiveAlertsForLocation::sorted(collect($grouped)->map(fn (array $group): ActiveWwa => $group['wwa']));
        $shown = $sorted->take(self::MAX_EVENTS);

        $events = $shown->map(fn (ActiveWwa $wwa): array => $this->eventRow($user, $wwa, $grouped[$wwa->id]['locations']))->values()->all();

        return ['events' => $events, 'more' => max(0, $sorted->count() - self::MAX_EVENTS), 'has_locations' => true, 'capped' => $capped];
    }

    /**
     * @param  list<Location>  $locations
     * @return array<string, mixed>
     */
    private function eventRow(User $user, ActiveWwa $wwa, array $locations): array
    {
        $locations = collect($locations)->sortBy('label')->values();
        $timezone = Timezones::forLocation($locations->first()) ?? config('app.timezone');

        $logs = DeliveryLog::query()
            ->where('user_id', $user->id)
            ->whereIn('ingested_product_id', IngestedProduct::query()->select('id')->where(ActiveWwa::vtecKeyFor((new IngestedProduct)->forceFill([
                'vtec_office' => $wwa->vtec_office, 'vtec_phenomenon' => $wwa->vtec_phenomenon, 'vtec_significance' => $wwa->vtec_significance,
                'vtec_event_number' => $wwa->vtec_event_number, 'vtec_year' => $wwa->vtec_year,
            ])))->where('is_test', false))
            ->with(['alertRule.products', 'alertRule.location'])
            ->orderByDesc('dispatched_at')
            ->get();

        $successes = $logs->where('status', 'success');
        $status = match (true) {
            $successes->isNotEmpty() => 'alerted',
            $logs->where('status', 'suppressed')->isNotEmpty() => 'held',
            $logs->where('status', 'failure')->isNotEmpty() => 'failed',
            default => 'none',
        };

        return [
            'key' => $wwa->id,
            'event' => $wwa->product?->name ?? 'Weather alert',
            'family' => $wwa->product?->family?->value,
            'locations' => $locations->pluck('label')->map(fn ($label): string => (string) $label)->all(),
            'expires' => $wwa->expires?->copy()->setTimezone($timezone)->toIso8601String(),
            'status' => $status,
            'alerted_via' => $successes->map(fn (DeliveryLog $log): string => $log->alertRule?->displayName() ?? 'one of your alerts')->unique()->values()->all(),
            'alerted_at' => $successes->first()?->dispatched_at?->copy()->setTimezone($timezone)->toIso8601String(),
        ];
    }
}
```

(`IngestedProduct::query()->where($array)` with a `vtec_*` array works because `vtecKeyFor()` returns exactly the five columns; if `forceFill` on a throwaway model feels clumsy, extract a small `ActiveWwa::vtecKey(): array` accessor — keep one definition of the key. The `Collection` import may be unused; let Pint remove it. The query-per-event cost is bounded by the 6-event cap.)

- [ ] **Step 5: The widget, view and registration**

`app/Filament/Dashboard/Resources/AlertRules/Widgets/ActiveNowWidget.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Widgets;

use App\Actions\Alerts\GetActiveNowAtMyLocations;
use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use Filament\Widgets\Widget;

/**
 * "Active now at your locations" above the My Alerts table. Reads only our own
 * active_wwas and delivery_logs (no external call), scoped to auth()->user()
 * inside the action; the view polls every 60 s and the action caches for 60 s.
 */
class ActiveNowWidget extends Widget
{
    protected string $view = 'filament.dashboard.widgets.active-now';

    protected int|string|array $columnSpan = 1;

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return AlertRuleResource::canAccess();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return app(GetActiveNowAtMyLocations::class)(auth()->user());
    }
}
```

`resources/views/filament/dashboard/widgets/active-now.blade.php`:

```blade
<x-filament-widgets::widget>
    <x-filament::section heading="Active now at your locations" compact>
        <div wire:poll.60s>
            @if (! $has_locations)
                <p class="text-sm text-gray-600 dark:text-gray-300">Add a location to see active weather alerts here.</p>
            @elseif ($events === [])
                <p class="text-sm text-gray-600 dark:text-gray-300">No active weather alerts at your locations.</p>
            @else
                <ul class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($events as $event)
                        <li class="py-2 text-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-filament::badge :color="match ($event['family']) { 'warning' => 'danger', 'watch' => 'warning', 'advisory' => 'info', default => 'gray' }" size="sm">{{ $event['event'] }}</x-filament::badge>
                                <span class="text-gray-700 dark:text-gray-300">{{ implode(', ', $event['locations']) }}</span>
                                @if ($event['expires'])
                                    <span class="text-gray-500 dark:text-gray-400">until {{ \Illuminate\Support\Carbon::parse($event['expires'])->format('g:i A') }}</span>
                                @endif
                            </div>
                            <div class="mt-1">
                                @switch($event['status'])
                                    @case('alerted')
                                        <span class="text-success-700 dark:text-success-400">Alerted via {{ implode(', ', $event['alerted_via']) }}@if ($event['alerted_at']) · {{ \Illuminate\Support\Carbon::parse($event['alerted_at'])->format('g:i A') }}@endif</span>
                                        @break
                                    @case('held')
                                        <span class="text-warning-700 dark:text-warning-400">Held during quiet hours</span>
                                        @break
                                    @case('failed')
                                        <span class="text-danger-700 dark:text-danger-400">Delivery failed</span>
                                        @break
                                    @default
                                        <span class="font-medium text-danger-700 dark:text-danger-400">None of your alerts matched this warning</span>
                                @endswitch
                            </div>
                        </li>
                    @endforeach
                </ul>

                @if ($more > 0)
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">and {{ $more }} more</p>
                @endif

                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">"None of your alerts matched" means no alert was sent for this warning. A warning issued before you created an alert may show this even though updates to it will reach you.@if ($capped) Showing your first 25 locations.@endif</p>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
```

Register: add `ActiveNowWidget::class` **last** in both `AlertRuleResource::getWidgets()` and `ListAlertRules::getHeaderWidgets()`.

Append to `.ai/rules/filament.md`:

```markdown
## My Alerts widgets read only the database, scope to the user, and derive coverage from delivery logs
The My Alerts header widgets (AlertStatsWidget, NeedsAttentionWidget, ActiveNowWidget) are thin Filament classes over Filament-free actions in `app/Actions/Alerts` that take the `User`; the resource's `canView()` allows everyone, so scoping every query to that user is the protection. They make no external calls. "Covered by your alerts" in ActiveNowWidget is evidence from `delivery_logs` joined to the active warning through the shared VTEC event key (`ActiveWwa::vtecKeyFor()` / the `vtec_*` columns on `IngestedProduct`) — never a re-implementation of `MatchWeatherAlertRules`; a warning issued before an alert existed correctly shows "none". Subscriber-supplied names are rendered with Blade `{{ }}` only, failure rows never expose `error_detail` or a destination, and `GetActiveAlertsForLocation::activeFor()` / `sorted()` are the shared source (and comparator) for active warnings.
```

- [ ] **Step 6: Run to verify it passes, plus the suites around the refactor**

Run: `tests/Feature/GetActiveNowAtMyLocationsTest.php tests/Feature/AlertWidgetsTest.php tests/Feature/GetActiveAlertsForLocationTest.php tests/Feature/BuildDailySummaryReportTest.php tests/Feature/GetAlertsNeedingAttentionTest.php tests/Feature/GetAlertStatsTest.php`
Expected: all PASS.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Alerts/GetActiveNowAtMyLocations.php app/Actions/Weather/GetActiveAlertsForLocation.php app/Filament/Dashboard/Resources/AlertRules/Widgets/ActiveNowWidget.php resources/views/filament/dashboard/widgets/active-now.blade.php app/Filament/Dashboard/Resources/AlertRules/AlertRuleResource.php app/Filament/Dashboard/Resources/AlertRules/Pages/ListAlertRules.php .ai/rules/filament.md tests/Feature/GetActiveNowAtMyLocationsTest.php tests/Feature/AlertWidgetsTest.php
git commit -m "Add an Active now widget with delivery-based coverage to My Alerts

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Post-implementation verification (controller, in a real browser)

1. Create a throwaway Pro subscriber with: two locations (Denver area, with `forecast_zone_ugc`/`county_ugc` set) and a mobile location with no position; a verified channel with a recent failed delivery as its latest attempt, an unverified channel used by an active alert, an inactive alert, an active Daily Summary on the mobile location; seeded `delivery_logs` across 14 days (successes, a failure, a `suppressed` row); two active warnings covering the Denver locations (one with a matching successful delivery to an ingested product sharing its VTEC key, one without). Log in (Livewire login component; retry once if the first attempt doesn't redirect) and open `/dashboard/alert-rules` at 1280x900.
2. Confirm: the stats strip (counts, red failed stat, sparkline, trend text); Needs attention rows in order with working "Fix" links (alert edit, channels page, location edit); Active now shows the alerted warning ("Alerted via …") and the uncovered one in the attention color, with the footnote; the 60-second poll re-renders without errors; names like `<b>x</b>` render literally.
3. Empty states: a subscriber with nothing wrong and no locations sees "All your alerts look healthy" and "Add a location…".
4. Layout at phone width (`resize_window` mobile): widgets stack, nothing overflows. Check the browser console for errors.
5. Delete the throwaway data afterwards and reset the viewport.

---

## Self-Review (against the spec)

**Coverage:** four Needs-attention checks with failing-wins-over-unverified, active-only rules, ordering, 8-row cap, healthy state, no leakage (T2); four stats with 7-day windows, trend, sparkline, danger/success color, muted held stat (T1); Active now grouping, expiry timezone, evidence-based statuses and precedence, other-user isolation, test warnings and positionless locations excluded, family/expiry sort, 6-event cap, 60 s cache and poll, 25-location cap and footnote, empty states (T3); `activeFor()` extraction with unchanged output (T3 step 3 + test); layout, polling, canView, escaping, no external calls, registration order (all); rule recorded (T3); browser verification; non-goals untouched.

**Placeholder scan:** none. A few test notes tell the implementer to adapt to installed API names (Filament `Stat` accessors, route names, `assertSee` escape signature) or to a factory's unique columns, while keeping every assertion's intent; two code notes allow equivalent query forms (`DISTINCT ON`, a `vtecKey()` accessor).

**Type consistency:** the three action result shapes match the widgets' use and the tests; row `kind`/`severity`/`target` values are the same in the action, the widget `urlFor()`/`iconFor()` and the tests; `GetActiveAlertsForLocation::activeFor()`/`sorted()` signatures are used identically by the action and the extraction test; widget order is Stats, Needs attention, Active now in both registration lists.
