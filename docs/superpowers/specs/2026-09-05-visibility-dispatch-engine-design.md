# Visibility Dispatch Engine — Design

## Context

The Admin Tools Filament Blueprint's "Visibility" tool (`docs/admin-tools-filament-blueprint.md`) shipped a real, full-CRUD `ActivityAlertRuleResource` — staff can configure a rule (`scope`: all subscribers or specific subscribers, a set of `activity_types`, an Apprise target) — but deliberately left the real-time dispatch engine unbuilt, since building it half-designed risked a config UI that silently implied live coverage it didn't have. `ListActivityAlertRules` currently shows a persistent notice: *"Rules are configured but not yet enforced — real-time dispatch requires a separate activity-observer engine, planned separately."* This spec is that engine.

**Scope gap discovered before design started**: `App\Enums\ActivityAlertType` has 23 cases, but only 17 have a genuine signal to observe today:

- **Auth lifecycle** (3): `AccountCreated`, `SignedIn`, `SignedOut` — Laravel's native auth events, nothing app-specific exists yet.
- **User field changes** (4): `NameChanged`, `EmailChanged`, `PasswordChanged`, `MfaChanged` — no existing signal; `User`'s own `getActivitylogOptions()` deliberately logs only `is_staff`/`tier_override`/`tier_override_reason` (the staff-facing Audit Log's concern), not these subscriber-facing fields.
- **Account deletion** (1): `AccountDeleted` — a plain Eloquent `deleted` event on `User`, regardless of who triggered it.
- **Already-logged model CRUD** (9): `AlertCreated/Updated/Deleted`, `LocationCreated/Updated/Deleted`, and `ContactCreated/Updated/Deleted` (maps to `NotificationChannel`) — all real, already written into `activity_log` today via each model's existing `LogsActivity` setup.

The remaining 6 have no underlying feature at all: `ScheduleCreated/Updated/Deleted` (no separate Schedule model — quiet hours are two plain columns on `AlertRule`), `ProductListUpdated` (pivot attach/detach on `AlertRule↔Product` isn't captured by `LogsActivity`), `SettingsChanged` (no settings concept exists), `DailyForecastEnabled` (no such flag exists anywhere). **Decision: build dispatch for the 17 real types now; leave the other 6 selectable-but-inert in the rule form**, matching the pattern already used for Insight/Delivery waiting on the Alert Engine. `ListActivityAlertRules`'s notice gets updated to name which specific types are still inert, rather than a blanket "nothing works yet."

**Dispatch timing decision**: queued, not synchronous. A failed or slow Apprise call must not add latency to, or break, a login/logout/profile-save request. This is a deliberate departure from this codebase's prevailing synchronous-Action-class convention (`DispatchChannelConfirmationCode`, `RetireProduct`, etc.) — those are all requests where the outbound call *is* the point of the request; here it's a side effect of an unrelated request that shouldn't be allowed to affect it. Matches the precedent of the email-verification notification already being queued (`compose.yaml`'s `queue` service, added in an earlier session specifically because a `ShouldQueue` notification needs a worker).

**Matching key decision**: rules match on the affected record's *owner*, not the acting *causer*. The existing causer-resolver fix (`AppServiceProvider`) deliberately attributes an impersonated edit to the staff member for the Audit Log's purposes — that's a different concern from this tool's actual intent ("watch subscriber X's activity"), which should keep firing regardless of who was driving when X's record changed.

## Architecture

```
Signal sources                         Matching & dispatch                    Delivery
───────────────                        ────────────────────                    ────────

Illuminate\Auth\Events\Login    ─┐
Illuminate\Auth\Events\Logout    ┘─► ActivityAlertEventSubscriber
                                       (app/Listeners/)
                                              │
UserActivityObserver              ───────────┤──► MatchActivityAlertRules   ──► SendActivityAlert (queued Job)
(app/Observers/, on User:                    │     (app/Actions/Visibility/)         │
 created → AccountCreated;                   │      - queries active rules            ▼
 updated → diff name/email/                  │        matching type + scope    AppriseApiClient::notify()
 password/app_authentication_secret;         │      - one job dispatch per match
 deleted)                                    │
                                              │
ActivityLogObserver                ──────────┘
(app/Observers/, on
 Spatie\Activitylog\Models\Activity:
 created, for subject_type ∈
 {AlertRule, Location, NotificationChannel})
```

New directories: `app/Listeners/`, `app/Observers/` — first use of both in this codebase. All three registered in `AppServiceProvider::boot()`, alongside the existing causer-resolver registration.

## Signal sources

### `App\Listeners\ActivityAlertEventSubscriber`

One class using Laravel's event-subscriber pattern (two small, related handlers together, rather than two near-empty single-event listener classes):

- `handleLogin(Login $event)` → dispatch match for `$event->user`, `ActivityAlertType::SignedIn`
- `handleLogout(Logout $event)` → `SignedOut` (guard against `$event->user` being `null`, which Laravel's `Logout` event allows)

### `App\Observers\UserActivityObserver` (registered on `User`)

- `created(User $user)` → `AccountCreated`.

  **Correction from what was originally assumed here**: neither registration path actually fires `Illuminate\Auth\Events\Registered` — the normal Filament `Register` page fires `Filament\Auth\Events\Registered`, and Google SSO fires `DutchCodingCompany\FilamentSocialite\Events\Registered`, both different classes entirely (confirmed by reading the actual vendor source). Subscribing to the wrong event meant `AccountCreated` never fired for *either* path, not just SSO as originally assumed. Fixed by moving `AccountCreated` off the auth-event subscriber entirely and onto `UserActivityObserver::created()` (an Eloquent `created` hook on `User` itself) — this fires reliably regardless of which vendor flow created the row, sidestepping the event-class fragmentation problem rather than chasing every vendor's own event class.
- `updated(User $user)`: independently checks `$user->wasChanged('name')` → `NameChanged`, `wasChanged('email')` → `EmailChanged`, `wasChanged('password')` → `PasswordChanged`, `wasChanged('app_authentication_secret')` → `MfaChanged`. More than one can fire from a single save (e.g. changing both name and email at once) — each is matched/dispatched independently.
- `deleted(User $user)` → `AccountDeleted`.

### `App\Observers\ActivityLogObserver` (registered on `Spatie\Activitylog\Models\Activity`)

- `created(Activity $activity)`: looks up `($activity->subject_type, $activity->event)` in a small internal map:
  ```
  [AlertRule::class, 'created']            => ActivityAlertType::AlertCreated
  [AlertRule::class, 'updated']            => ActivityAlertType::AlertUpdated
  [AlertRule::class, 'deleted']            => ActivityAlertType::AlertDeleted
  [Location::class, 'created']             => ActivityAlertType::LocationCreated
  [Location::class, 'updated']             => ActivityAlertType::LocationUpdated
  [Location::class, 'deleted']             => ActivityAlertType::LocationDeleted
  [NotificationChannel::class, 'created']  => ActivityAlertType::ContactCreated
  [NotificationChannel::class, 'updated']  => ActivityAlertType::ContactUpdated
  [NotificationChannel::class, 'deleted']  => ActivityAlertType::ContactDeleted
  ```
  Unrecognized combinations (e.g. `Product`'s or `User`'s own audit-log activity) no-op silently — this observer only ever reacts to the nine mapped combinations. Resolves the owning subscriber via `$activity->subject->user` (all three subject types have a `user()` `belongsTo`) — but `subject()` is a plain `morphTo()` that does a live query, so it returns `null` after a hard delete; for the three `deleted` cases, the owning user id must instead come from `$activity->properties` (Spatie's before-deletion attribute snapshot). This requires exactly one small config change, found while designing this: **`NotificationChannel::getActivitylogOptions()` currently excludes `user_id` from `logOnly(['channel_type', 'label', 'verified_at'])`** — add `user_id` there (it isn't sensitive, unlike `apprise_url`, which stays excluded for the reason already documented on that model). `AlertRule` and `Location` both already use `logAll()`, so `user_id` is already in their snapshots — no change needed for those two. (`$activity->causer_id` is deliberately *not* used as a fallback here — per the owner-vs-causer decision above, a staff member deleting a subscriber's channel while impersonating would resolve to the staff member via causer, which is exactly the wrong answer.)

## Matching & dispatch

### `App\Actions\Visibility\MatchActivityAlertRules`

```php
(new MatchActivityAlertRules)(User $user, ActivityAlertType $type): void
```

Queries:
```php
ActivityAlertRule::query()
    ->where('is_active', true)
    ->whereJsonContains('activity_types', $type->value)
    ->where(function ($query) use ($user) {
        $query->where('scope', ActivityAlertScope::AllSubscribers)
            ->orWhere(function ($query) use ($user) {
                $query->where('scope', ActivityAlertScope::SpecificUsers)
                    ->whereHas('users', fn ($q) => $q->whereKey($user->getKey()));
            });
    })
    ->get();
```

For each matched rule, dispatches `SendActivityAlert::dispatch($rule->id, $type, $user->id, now())`.

### `App\Jobs\SendActivityAlert` (`ShouldQueue`)

Constructor takes primitive/serializable values (rule id, the enum case, user id, an occurred-at timestamp) rather than model instances, so a rule deleted between dispatch and processing is a normal, silent no-op rather than a `ModelNotFoundException`:

```php
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
```

A failed Apprise call fails the job normally (visible via `failed_jobs`/queue tooling) — no bespoke retry logic. This is an ops-monitoring tool, not a delivery-guaranteed subscriber notification; Laravel's queue defaults are enough.

## Testing plan

- `ActivityAlertEventSubscriberTest` (or folded into a broader feature test): firing `Login`/`Logout` dispatches `SendActivityAlert` for a matching active rule (`Queue::fake()` + `Queue::assertPushed`).
- `UserActivityObserverTest`: creating a user dispatches `AccountCreated`; updating a user's name/email/password/`app_authentication_secret` independently each dispatch the right type (and a save touching two of them dispatches both); deleting a user dispatches `AccountDeleted`.
- `ActivityLogObserverTest`: creating/updating/deleting an `AlertRule`, `Location`, and `NotificationChannel` each dispatch the right type with the correct owning user resolved; an activity row for `Product`/`User` (the staff-facing audit fields) dispatches nothing.
- `MatchActivityAlertRulesTest`: an `all_subscribers` rule matches any user; a `specific_users` rule matches only pivoted users; an inactive rule never matches; a rule whose `activity_types` doesn't include the type never matches.
- `SendActivityAlertTest`: calls `AppriseApiClient::notify()` with the expected url/body/title (`Http::fake()`); silently no-ops if the rule or user no longer exists by the time the job runs.
- Update `ActivityAlertRuleResourceTest` (if it asserts on the list page's current blanket notice) to match the revised, more specific notice text.

## Out of scope (unchanged from the original Blueprint decision)

`ScheduleCreated/Updated/Deleted`, `ProductListUpdated`, `SettingsChanged`, `DailyForecastEnabled` remain selectable in the rule form's `CheckboxList` but dispatch nothing — no underlying feature exists yet to observe. `ListActivityAlertRules`'s notice is updated to name these six specifically as still inert, rather than the current blanket "not yet enforced" covering everything.
