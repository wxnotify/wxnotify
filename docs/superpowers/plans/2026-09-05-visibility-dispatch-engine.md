# Visibility Dispatch Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `ActivityAlertRule` actually fire — a real-time engine that watches for 17 of the 23 `ActivityAlertType` cases (the ones with a genuine signal today) and dispatches a queued Apprise notification per matching active rule.

**Architecture:** Three signal sources (a Laravel auth-event subscriber for sign-in/out/registration; an Eloquent observer on `User` for name/email/password/MFA-secret changes and deletion; an Eloquent observer on Spatie's `Activity` model reacting to already-logged `AlertRule`/`Location`/`NotificationChannel` CRUD) all call one shared action that queries active `ActivityAlertRule`s and dispatches one queued job per match. The job calls the existing `AppriseApiClient`.

**Tech Stack:** Laravel 13 / PHP 8.5, `spatie/laravel-activitylog` (already installed, already logging the three CRUD models), Laravel's queue system (`QUEUE_CONNECTION=database` in dev, a `queue` worker service already running in `compose.yaml`; `QUEUE_CONNECTION=sync` in tests via `phpunit.xml`), Pest.

---

## Context (read this first — do not re-derive)

This implements the dispatch half of `docs/admin-tools-filament-blueprint.md`'s "Visibility" tool. The config surface (`ActivityAlertRuleResource`, the `ActivityAlertRule` model + migration + `activity_alert_rule_user` pivot, `App\Enums\ActivityAlertType`, `App\Enums\ActivityAlertScope`) already exists and is fully working — this plan adds nothing to that surface except updating one notice string (Task 6). The full design rationale, including why 6 of 23 enum cases are deliberately left unreachable and three specific technical findings (properties-snapshot structure on Spatie deletion events, the `NotificationChannel` `logOnly` gap, the Google-SSO `Registered`-event gap), is in `docs/superpowers/specs/2026-09-05-visibility-dispatch-engine-design.md` — read it if anything below needs more rationale than what's repeated here.

**Key facts already verified, don't re-verify:**
- `User::getActivitylogOptions()` only logs `['is_staff', 'tier_override', 'tier_override_reason']` — the existing `activity_log` rows for `User` are useless for this feature (different concern, the staff Audit Log). Name/email/password/MFA changes need their own dedicated observer.
- `AlertRule` and `Location` both use `LogOptions::defaults()->logAll()->...` — `user_id` is already in their logged attribute snapshots at every event, including `deleted`.
- `NotificationChannel` uses `->logOnly(['channel_type', 'label', 'verified_at'])` — `user_id` is **not** currently logged, which breaks `ContactDeleted`'s owner resolution. Task 3 fixes this.
- Read directly from `vendor/spatie/laravel-activitylog/src/Models/Concerns/LogsActivity.php`'s `buildChanges()` method (lines ~236-263): a `created` event's `properties` is `['attributes' => [...]]`; an `updated` event (with `logOnlyDirty`) is `['attributes' => [...new dirty...], 'old' => [...old dirty...]]`; a `deleted` event is `['old' => [...the full attributesToBeLogged() snapshot...]]` — no `'attributes'` key. This is why the `ActivityLogObserver` (Task 3) only needs the properties-snapshot fallback for the `deleted` case — `created`/`updated` can use the still-live `$activity->subject` relation directly.
- `Spatie\Activitylog\Models\Activity::getProperty(string $propertyName, mixed $default = null)` is a public method (`Arr::get()` under the hood) supporting dot-notation — use it instead of reaching into `->properties` directly.
- No `app/Listeners/`, `app/Observers/`, or `app/Jobs/` directories exist yet in this codebase — this plan creates all three.
- `AppServiceProvider::boot()` already registers one thing (the causer-resolver fix for impersonation) — this plan adds three more registrations there (Task 6), it does not replace the existing one.

---

### Task 1: `SendActivityAlert` queued job

**Files:**
- Create: `app/Jobs/SendActivityAlert.php`
- Test: `tests/Feature/SendActivityAlertTest.php`

- [ ] **Step 1: Write the failing tests**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest SendActivityAlertTest --no-interaction"`

Replace `tests/Feature/SendActivityAlertTest.php` with:

```php
<?php

use App\Enums\ActivityAlertType;
use App\Jobs\SendActivityAlert;
use App\Models\ActivityAlertRule;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

test('it sends an apprise notification for an active rule', function () {
    Http::fake();

    $rule = ActivityAlertRule::factory()->create([
        'apprise_target' => 'slack://token/botname/channel',
        'is_active' => true,
    ]);
    $user = User::factory()->create(['email' => 'subscriber@example.com']);
    $occurredAt = Carbon::parse('2026-09-05 12:00:00');

    (new SendActivityAlert($rule->id, ActivityAlertType::SignedIn, $user->id, $occurredAt))->handle();

    Http::assertSent(fn ($request) => $request['urls'] === 'slack://token/botname/channel'
        && str_contains($request['body'], 'Signed in: subscriber@example.com')
        && $request['title'] === 'WxNotify Activity Alert');
});

test('it does nothing if the rule no longer exists', function () {
    Http::fake();

    $user = User::factory()->create();

    (new SendActivityAlert(999999, ActivityAlertType::SignedIn, $user->id, now()))->handle();

    Http::assertNothingSent();
});

test('it does nothing if the user no longer exists', function () {
    Http::fake();

    $rule = ActivityAlertRule::factory()->create();

    (new SendActivityAlert($rule->id, ActivityAlertType::SignedIn, 999999, now()))->handle();

    Http::assertNothingSent();
});

test('it does nothing if the rule has since been deactivated', function () {
    Http::fake();

    $rule = ActivityAlertRule::factory()->create(['is_active' => false]);
    $user = User::factory()->create();

    (new SendActivityAlert($rule->id, ActivityAlertType::SignedIn, $user->id, now()))->handle();

    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run the tests, confirm they fail**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/SendActivityAlertTest.php --compact"`

Expected: FAIL — `Class "App\Jobs\SendActivityAlert" not found`.

- [ ] **Step 3: Implement the job**

Create `app/Jobs/SendActivityAlert.php`:

```php
<?php

namespace App\Jobs;

use App\Enums\ActivityAlertType;
use App\Models\ActivityAlertRule;
use App\Models\User;
use App\Support\Apprise\AppriseApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class SendActivityAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $ruleId,
        public readonly ActivityAlertType $type,
        public readonly int $userId,
        public readonly Carbon $occurredAt,
    ) {}

    public function handle(): void
    {
        $rule = ActivityAlertRule::find($this->ruleId);
        $user = User::find($this->userId);

        if ($rule === null || $user === null || ! $rule->is_active) {
            return;
        }

        app(AppriseApiClient::class)->notify(
            url: $rule->apprise_target,
            body: "{$this->type->label()}: {$user->email} at {$this->occurredAt->toDayDateTimeString()}",
            title: 'WxNotify Activity Alert',
        );
    }
}
```

(Takes primitive ids rather than model instances so a rule deleted between dispatch and processing is a normal no-op, not a queue failure — the job re-fetches fresh state every time it runs, which also naturally handles a rule being deactivated in the meantime.)

- [ ] **Step 4: Run the tests, confirm they pass**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/SendActivityAlertTest.php --compact"`

Expected: `Tests: 4 passed`

- [ ] **Step 5: Pint, full suite, commit**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent && vendor/bin/pest --compact"
git add app/Jobs/SendActivityAlert.php tests/Feature/SendActivityAlertTest.php
git commit -m "Add the SendActivityAlert queued job

Dispatches an Apprise notification for one matched ActivityAlertRule.
Takes ids rather than model instances so a rule deleted or deactivated
between dispatch and processing is a silent no-op, not a failure.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

The full-suite run must show every test passing, not just this task's new file.

---

### Task 2: `MatchActivityAlertRules` action

**Files:**
- Create: `app/Actions/Visibility/MatchActivityAlertRules.php`
- Test: `tests/Feature/MatchActivityAlertRulesTest.php`

- [ ] **Step 1: Write the failing tests**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest MatchActivityAlertRulesTest --no-interaction"`

Replace `tests/Feature/MatchActivityAlertRulesTest.php` with:

```php
<?php

use App\Actions\Visibility\MatchActivityAlertRules;
use App\Enums\ActivityAlertType;
use App\Jobs\SendActivityAlert;
use App\Models\ActivityAlertRule;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('an all_subscribers active rule matching the type dispatches a job for any user', function () {
    Queue::fake();

    $rule = ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::SignedIn->value],
        'is_active' => true,
    ]);
    $user = User::factory()->create();

    (new MatchActivityAlertRules)($user, ActivityAlertType::SignedIn);

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->ruleId === $rule->id && $job->userId === $user->id);
});

test('a specific_users rule only matches a pivoted user', function () {
    Queue::fake();

    $watched = User::factory()->create();
    $other = User::factory()->create();
    $rule = ActivityAlertRule::factory()->create([
        'scope' => 'specific_users',
        'activity_types' => [ActivityAlertType::SignedIn->value],
        'is_active' => true,
    ]);
    $rule->users()->attach($watched);

    (new MatchActivityAlertRules)($watched, ActivityAlertType::SignedIn);
    (new MatchActivityAlertRules)($other, ActivityAlertType::SignedIn);

    Queue::assertPushed(SendActivityAlert::class, 1);
});

test('an inactive rule never matches', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::SignedIn->value],
        'is_active' => false,
    ]);
    $user = User::factory()->create();

    (new MatchActivityAlertRules)($user, ActivityAlertType::SignedIn);

    Queue::assertNotPushed(SendActivityAlert::class);
});

test('a rule not covering the given type never matches', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::SignedOut->value],
        'is_active' => true,
    ]);
    $user = User::factory()->create();

    (new MatchActivityAlertRules)($user, ActivityAlertType::SignedIn);

    Queue::assertNotPushed(SendActivityAlert::class);
});
```

- [ ] **Step 2: Run the tests, confirm they fail**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/MatchActivityAlertRulesTest.php --compact"`

Expected: FAIL — `Class "App\Actions\Visibility\MatchActivityAlertRules" not found`.

- [ ] **Step 3: Implement the action**

Create `app/Actions/Visibility/MatchActivityAlertRules.php`:

```php
<?php

namespace App\Actions\Visibility;

use App\Enums\ActivityAlertScope;
use App\Enums\ActivityAlertType;
use App\Jobs\SendActivityAlert;
use App\Models\ActivityAlertRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class MatchActivityAlertRules
{
    public function __invoke(User $user, ActivityAlertType $type): void
    {
        $occurredAt = now();

        ActivityAlertRule::query()
            ->where('is_active', true)
            ->whereJsonContains('activity_types', $type->value)
            ->where(function (Builder $query) use ($user) {
                $query->where('scope', ActivityAlertScope::AllSubscribers)
                    ->orWhere(function (Builder $query) use ($user) {
                        $query->where('scope', ActivityAlertScope::SpecificUsers)
                            ->whereHas('users', fn (Builder $q) => $q->whereKey($user->getKey()));
                    });
            })
            ->get()
            ->each(fn (ActivityAlertRule $rule) => SendActivityAlert::dispatch(
                $rule->id,
                $type,
                $user->id,
                $occurredAt,
            ));
    }
}
```

- [ ] **Step 4: Run the tests, confirm they pass**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/MatchActivityAlertRulesTest.php --compact"`

Expected: `Tests: 4 passed`

- [ ] **Step 5: Pint, full suite, commit**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent && vendor/bin/pest --compact"
git add app/Actions/Visibility/MatchActivityAlertRules.php tests/Feature/MatchActivityAlertRulesTest.php
git commit -m "Add MatchActivityAlertRules

Queries active ActivityAlertRules matching a given activity type and
scope (all subscribers, or the specific subscriber whose activity this
is), dispatching one SendActivityAlert job per match. The next three
tasks wire real signal sources into this.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

The full-suite run must show every test passing.

---

### Task 3: `ActivityLogObserver` (already-logged model CRUD)

**Files:**
- Modify: `app/Models/NotificationChannel.php`
- Create: `app/Observers/ActivityLogObserver.php`
- Test: `tests/Feature/ActivityLogObserverTest.php`

- [ ] **Step 1: Fix `NotificationChannel`'s `logOnly` to include `user_id`**

In `app/Models/NotificationChannel.php`, change:

```php
        return LogOptions::defaults()
            ->logOnly(['channel_type', 'label', 'verified_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
```

to:

```php
        return LogOptions::defaults()
            ->logOnly(['user_id', 'channel_type', 'label', 'verified_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
```

(`user_id` isn't sensitive, unlike `apprise_url` which stays excluded for the reason already documented in this file's comment. Needed so a `deleted` event's properties snapshot — the only place the owning subscriber can be resolved from once the row is gone — actually contains it. `AlertRule` and `Location` already use `logAll()`, so they need no equivalent change.)

- [ ] **Step 2: Write the failing tests**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest ActivityLogObserverTest --no-interaction"`

Replace `tests/Feature/ActivityLogObserverTest.php` with:

**Every test below seeds a real, matching `ActivityAlertRule` config row before triggering the model event.** `MatchActivityAlertRules` (Task 2) only dispatches a job when a real active rule matches — without a seeded rule, `ActivityLogObserver` would call it correctly but nothing would ever get pushed, and every assertion below would fail for the wrong reason. `Queue::fake()` is always the first line in every test, before any model is created, so no test can accidentally trigger a real synchronous dispatch (`QUEUE_CONNECTION=sync` in `phpunit.xml`) against a real Apprise call.

```php
<?php

use App\Enums\ActivityAlertType;
use App\Jobs\SendActivityAlert;
use App\Models\ActivityAlertRule;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use Illuminate\Support\Facades\Queue;

test('creating an alert rule dispatches AlertCreated for its owner', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::AlertCreated->value],
        'is_active' => true,
    ]);

    $alertRule = AlertRule::factory()->create();

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::AlertCreated
        && $job->userId === $alertRule->user_id);
});

test('updating an alert rule dispatches AlertUpdated', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::AlertUpdated->value],
        'is_active' => true,
    ]);

    $alertRule = AlertRule::factory()->create();
    $alertRule->update(['name' => 'New name']);

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::AlertUpdated
        && $job->userId === $alertRule->user_id);
});

test('deleting an alert rule dispatches AlertDeleted, resolving the owner from the deletion snapshot', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::AlertDeleted->value],
        'is_active' => true,
    ]);

    $alertRule = AlertRule::factory()->create();
    $ownerId = $alertRule->user_id;
    $alertRule->delete();

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::AlertDeleted
        && $job->userId === $ownerId);
});

test('creating, updating, and deleting a location dispatch the matching Location types', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [
            ActivityAlertType::LocationCreated->value,
            ActivityAlertType::LocationUpdated->value,
            ActivityAlertType::LocationDeleted->value,
        ],
        'is_active' => true,
    ]);

    $location = Location::factory()->create();
    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::LocationCreated);

    $location->update(['label' => 'New label']);
    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::LocationUpdated);

    $ownerId = $location->user_id;
    $location->delete();
    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::LocationDeleted
        && $job->userId === $ownerId);
});

test('creating, updating, and deleting a notification channel dispatch the matching Contact types', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [
            ActivityAlertType::ContactCreated->value,
            ActivityAlertType::ContactUpdated->value,
            ActivityAlertType::ContactDeleted->value,
        ],
        'is_active' => true,
    ]);

    $channel = NotificationChannel::factory()->create();
    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::ContactCreated);

    $channel->update(['label' => 'New label']);
    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::ContactUpdated);

    $ownerId = $channel->user_id;
    $channel->delete();
    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::ContactDeleted
        && $job->userId === $ownerId);
});

test('activity for an unmapped subject type dispatches nothing, even when every type is configured', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => array_column(ActivityAlertType::cases(), 'value'),
        'is_active' => true,
    ]);

    Product::factory()->create();

    Queue::assertNotPushed(SendActivityAlert::class);
});
```

- [ ] **Step 3: Run the tests, confirm they fail**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ActivityLogObserverTest.php --compact"`

Expected: FAIL — nothing dispatches yet (the observer doesn't exist and isn't registered).

- [ ] **Step 4: Implement the observer**

Create `app/Observers/ActivityLogObserver.php`:

```php
<?php

namespace App\Observers;

use App\Actions\Visibility\MatchActivityAlertRules;
use App\Enums\ActivityAlertType;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

class ActivityLogObserver
{
    /**
     * @var array<class-string, array<string, ActivityAlertType>>
     */
    private const TYPE_MAP = [
        AlertRule::class => [
            'created' => ActivityAlertType::AlertCreated,
            'updated' => ActivityAlertType::AlertUpdated,
            'deleted' => ActivityAlertType::AlertDeleted,
        ],
        Location::class => [
            'created' => ActivityAlertType::LocationCreated,
            'updated' => ActivityAlertType::LocationUpdated,
            'deleted' => ActivityAlertType::LocationDeleted,
        ],
        NotificationChannel::class => [
            'created' => ActivityAlertType::ContactCreated,
            'updated' => ActivityAlertType::ContactUpdated,
            'deleted' => ActivityAlertType::ContactDeleted,
        ],
    ];

    public function created(Activity $activity): void
    {
        $type = self::TYPE_MAP[$activity->subject_type][$activity->event] ?? null;

        if ($type === null) {
            return;
        }

        // subject() is a live morphTo lookup — fine for created/updated, but
        // returns null after a hard delete, so 'deleted' has to read the
        // owning user id out of Spatie's own before-deletion snapshot
        // instead (see LogsActivity::buildChanges() — a deleted event's
        // properties are ['old' => <full attributesToBeLogged() snapshot>],
        // no 'attributes' key).
        $userId = $activity->event === 'deleted'
            ? $activity->getProperty('old.user_id')
            : $activity->subject?->user_id;

        if ($userId === null) {
            return;
        }

        $user = User::find($userId);

        if ($user === null) {
            return;
        }

        app(MatchActivityAlertRules::class)($user, $type);
    }
}
```

- [ ] **Step 5: Register the observer**

In `app/Providers/AppServiceProvider.php`, add to the top of `boot()` (before or after the existing causer-resolver code — order doesn't matter, they're independent):

```php
use App\Observers\ActivityLogObserver;
use Spatie\Activitylog\Models\Activity;
```

```php
Activity::observe(ActivityLogObserver::class);
```

- [ ] **Step 6: Run the tests, confirm they pass**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ActivityLogObserverTest.php --compact"`

Expected: `Tests: 6 passed`

- [ ] **Step 7: Pint, full suite, commit**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent && vendor/bin/pest --compact"
git add app/Models/NotificationChannel.php app/Observers/ActivityLogObserver.php app/Providers/AppServiceProvider.php tests/Feature/ActivityLogObserverTest.php
git commit -m "Wire AlertRule/Location/NotificationChannel CRUD into Visibility dispatch

Observes Spatie's Activity model (already being written to by all
three models' existing LogsActivity setup) rather than the models
directly, and maps subject_type + event to the right
ActivityAlertType. Also fixes NotificationChannel's logOnly to include
user_id — needed to resolve the owning subscriber for a deleted
channel, since the subject relation is gone by then.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

The full-suite run must show every test passing.

---

### Task 4: `UserActivityObserver` (field changes + deletion)

**Files:**
- Create: `app/Observers/UserActivityObserver.php`
- Test: `tests/Feature/UserActivityObserverTest.php`

- [ ] **Step 1: Write the failing tests**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest UserActivityObserverTest --no-interaction"`

Replace `tests/Feature/UserActivityObserverTest.php` with:

**Every test seeds a real, matching `ActivityAlertRule` first, with `Queue::fake()` as the very first line** — same reasoning as Task 3 (see the note above its test block).

```php
<?php

use App\Enums\ActivityAlertType;
use App\Jobs\SendActivityAlert;
use App\Models\ActivityAlertRule;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('changing a user\'s name dispatches NameChanged', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::NameChanged->value],
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $user->update(['name' => 'New Name']);

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::NameChanged
        && $job->userId === $user->id);
});

test('changing a user\'s email dispatches EmailChanged', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::EmailChanged->value],
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $user->update(['email' => 'new@example.com']);

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::EmailChanged);
});

test('changing a user\'s password dispatches PasswordChanged', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::PasswordChanged->value],
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $user->update(['password' => 'a-new-strong-password']);

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::PasswordChanged);
});

test('changing the app authentication secret dispatches MfaChanged', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::MfaChanged->value],
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $user->forceFill(['app_authentication_secret' => 'a-new-secret'])->save();

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::MfaChanged);
});

test('changing two fields at once dispatches both types', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::NameChanged->value, ActivityAlertType::EmailChanged->value],
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $user->update(['name' => 'New Name', 'email' => 'new2@example.com']);

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::NameChanged);
    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::EmailChanged);
});

test('deleting a user dispatches AccountDeleted', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::AccountDeleted->value],
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $userId = $user->id;
    $user->delete();

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::AccountDeleted
        && $job->userId === $userId);
});

test('an unrelated field change dispatches nothing, even when every type is configured', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => array_column(ActivityAlertType::cases(), 'value'),
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $user->update(['is_staff' => true]);

    Queue::assertNotPushed(SendActivityAlert::class);
});
```

- [ ] **Step 2: Run the tests, confirm they fail**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/UserActivityObserverTest.php --compact"`

Expected: FAIL (the last test, "an unrelated field change dispatches nothing," will already pass trivially — everything else fails since nothing dispatches yet).

- [ ] **Step 3: Implement the observer**

Create `app/Observers/UserActivityObserver.php`:

```php
<?php

namespace App\Observers;

use App\Actions\Visibility\MatchActivityAlertRules;
use App\Enums\ActivityAlertType;
use App\Models\User;

class UserActivityObserver
{
    public function updated(User $user): void
    {
        $match = app(MatchActivityAlertRules::class);

        if ($user->wasChanged('name')) {
            $match($user, ActivityAlertType::NameChanged);
        }

        if ($user->wasChanged('email')) {
            $match($user, ActivityAlertType::EmailChanged);
        }

        if ($user->wasChanged('password')) {
            $match($user, ActivityAlertType::PasswordChanged);
        }

        if ($user->wasChanged('app_authentication_secret')) {
            $match($user, ActivityAlertType::MfaChanged);
        }
    }

    public function deleted(User $user): void
    {
        app(MatchActivityAlertRules::class)($user, ActivityAlertType::AccountDeleted);
    }
}
```

- [ ] **Step 4: Register the observer**

In `app/Providers/AppServiceProvider.php`, add:

```php
use App\Observers\UserActivityObserver;
use App\Models\User;
```

```php
User::observe(UserActivityObserver::class);
```

- [ ] **Step 5: Run the tests, confirm they pass**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/UserActivityObserverTest.php --compact"`

Expected: `Tests: 7 passed`

- [ ] **Step 6: Pint, full suite, commit**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent && vendor/bin/pest --compact"
git add app/Observers/UserActivityObserver.php app/Providers/AppServiceProvider.php tests/Feature/UserActivityObserverTest.php
git commit -m "Wire User field changes and deletion into Visibility dispatch

Deliberately separate from the staff Audit Log's own activity_log rows
for User (which only track is_staff/tier_override) — this is a
different concern, watching name/email/password/MFA-secret changes
that Audit Log doesn't and shouldn't need to care about.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

The full-suite run must show every test passing.

---

### Task 5: `ActivityAlertEventSubscriber` (auth lifecycle)

**Files:**
- Create: `app/Listeners/ActivityAlertEventSubscriber.php`
- Test: `tests/Feature/ActivityAlertEventSubscriberTest.php`

- [ ] **Step 1: Write the failing tests**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest ActivityAlertEventSubscriberTest --no-interaction"`

Replace `tests/Feature/ActivityAlertEventSubscriberTest.php` with:

**Every test seeds a real, matching `ActivityAlertRule` first, with `Queue::fake()` as the very first line** — same reasoning as Task 3 (see the note above its test block).

```php
<?php

use App\Enums\ActivityAlertType;
use App\Jobs\SendActivityAlert;
use App\Models\ActivityAlertRule;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Queue;

test('logging in dispatches SignedIn', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::SignedIn->value],
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    event(new Login('web', $user, false));

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::SignedIn
        && $job->userId === $user->id);
});

test('logging out dispatches SignedOut', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::SignedOut->value],
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    event(new Logout('web', $user));

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::SignedOut);
});

test('a logout event with no authenticated user dispatches nothing', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::SignedOut->value],
        'is_active' => true,
    ]);

    event(new Logout('web', null));

    Queue::assertNotPushed(SendActivityAlert::class);
});

test('registering dispatches AccountCreated', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::AccountCreated->value],
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    event(new Registered($user));

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::AccountCreated
        && $job->userId === $user->id);
});
```

- [ ] **Step 2: Run the tests, confirm they fail**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ActivityAlertEventSubscriberTest.php --compact"`

Expected: FAIL (the "no authenticated user" test will already pass trivially — everything else fails since nothing dispatches yet).

- [ ] **Step 3: Implement the subscriber**

Create `app/Listeners/ActivityAlertEventSubscriber.php`:

```php
<?php

namespace App\Listeners;

use App\Actions\Visibility\MatchActivityAlertRules;
use App\Enums\ActivityAlertType;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Events\Dispatcher;

class ActivityAlertEventSubscriber
{
    public function handleLogin(Login $event): void
    {
        app(MatchActivityAlertRules::class)($event->user, ActivityAlertType::SignedIn);
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user === null) {
            return;
        }

        app(MatchActivityAlertRules::class)($event->user, ActivityAlertType::SignedOut);
    }

    public function handleRegistered(Registered $event): void
    {
        app(MatchActivityAlertRules::class)($event->user, ActivityAlertType::AccountCreated);
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [self::class, 'handleLogin']);
        $events->listen(Logout::class, [self::class, 'handleLogout']);
        $events->listen(Registered::class, [self::class, 'handleRegistered']);
    }
}
```

(One subscriber class for all three related, small auth-lifecycle handlers, rather than three near-empty single-event listener classes — Laravel's documented event-subscriber pattern.)

- [ ] **Step 4: Register the subscriber**

In `app/Providers/AppServiceProvider.php`, add:

```php
use App\Listeners\ActivityAlertEventSubscriber;
use Illuminate\Support\Facades\Event;
```

```php
Event::subscribe(ActivityAlertEventSubscriber::class);
```

- [ ] **Step 5: Run the tests, confirm they pass**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ActivityAlertEventSubscriberTest.php --compact"`

Expected: `Tests: 4 passed`

- [ ] **Step 6: Pint, full suite, commit**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent && vendor/bin/pest --compact"
git add app/Listeners/ActivityAlertEventSubscriber.php app/Providers/AppServiceProvider.php tests/Feature/ActivityAlertEventSubscriberTest.php
git commit -m "Wire sign-in/sign-out/registration into Visibility dispatch

Subscribes to Laravel's native Login/Logout/Registered events. Known,
accepted gap (documented in the design spec): Google SSO registration
creates the User directly in a createUserUsing() closure rather than
through Filament's Register page, so it almost certainly never fires
Registered — AccountCreated will reliably fire for email/password
signups but likely miss SSO ones.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

The full-suite run must show every test passing. This is also the last task that adds new dispatch logic — after this, all 17 real `ActivityAlertType` cases are wired.

---

### Task 6: Update the list page notice, final verification

**Files:**
- Modify: `app/Filament/Admin/Resources/ActivityAlertRules/Pages/ListActivityAlertRules.php`
- Modify: `tests/Feature/ActivityAlertRuleResourceTest.php`

- [ ] **Step 1: Update the notice text**

In `app/Filament/Admin/Resources/ActivityAlertRules/Pages/ListActivityAlertRules.php`, the current `content()` method has:

```php
            Text::make('Rules are configured but not yet enforced — real-time dispatch requires a separate activity-observer engine, planned separately.')
                ->color('warning'),
```

Replace with:

```php
            Text::make('Real-time dispatch is live for most activity types below. Schedule changes, product list updates, settings changes, and Daily Forecast enablement are not yet enforced — those need features that don\'t exist in the app yet.')
                ->color('warning'),
```

- [ ] **Step 2: Update the existing test asserting the old notice text**

In `tests/Feature/ActivityAlertRuleResourceTest.php`, find:

```php
test('the list page shows the not-yet-enforced notice', function () {
    livewire(ListActivityAlertRules::class)
        ->assertSee('not yet enforced');
});
```

Replace with:

```php
test('the list page discloses which activity types are still not enforced', function () {
    livewire(ListActivityAlertRules::class)
        ->assertSee('not yet enforced')
        ->assertSee('Schedule changes');
});
```

- [ ] **Step 3: Run this resource's tests, confirm they pass**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ActivityAlertRuleResourceTest.php --compact"`

Expected: all pass (4 tests).

- [ ] **Step 4: Pint, full suite, commit**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent && vendor/bin/pest --compact"
git add app/Filament/Admin/Resources/ActivityAlertRules/Pages/ListActivityAlertRules.php tests/Feature/ActivityAlertRuleResourceTest.php
git commit -m "Name the still-inert activity types on the Activity Alert Rules list

Now that dispatch is live for 17 of 23 types, the blanket 'not yet
enforced' notice would be actively misleading. Names the four still
waiting on features that don't exist (Schedule, product-list pivot
logging, settings, Daily Forecast).

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

- [ ] **Step 5: Real end-to-end check against a live queue worker and Apprise**

This is worth doing for real, not just trusting the unit tests, since it exercises the entire chain: a real HTTP login → a real observer → a real queued job → the real `queue` worker container → a real Apprise API call → a real delivered notification. Use Mailpit (already running) as the observable endpoint, matching this project's established pattern for verifying outbound-email-shaped things locally (see `AppriseApiClient`'s own docblock note about `mailto://` needing `mode=insecure` + `from=` against an unencrypted relay).

1. Via `php artisan tinker` (or the admin UI), create a real rule:
   ```php
   App\Models\ActivityAlertRule::factory()->create([
       'name' => 'E2E verification',
       'scope' => 'all_subscribers',
       'activity_types' => ['signed_in'],
       'apprise_target' => 'mailto://mailpit:1025/?to=ops@wxnotify.test&mode=insecure&from=alerts@wxnotify.test',
       'is_active' => true,
   ]);
   ```
2. Log in via the browser as any real user (staff or subscriber).
3. Within a few seconds (the `queue` container runs `queue:listen`), check Mailpit's UI (`http://localhost:8025`) for a new email with subject "WxNotify Activity Alert" and a body containing "Signed in: <that user's email>".
4. Delete the verification rule afterward (`ActivityAlertRule::where('name', 'E2E verification')->delete()`) — it was only for this check, not a real rule the user asked for.

If the email doesn't arrive, check `docker compose logs queue` for a failed-job error before assuming the code is wrong — confirm whether the job ran and failed (a real bug) versus never ran at all (a queue infrastructure issue unrelated to this feature).

- [ ] **Step 6: Confirm final state**

```bash
git status --short
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact && vendor/bin/pint --dirty --format agent"
```

Expected: clean git status, full suite passing, Pint clean.
