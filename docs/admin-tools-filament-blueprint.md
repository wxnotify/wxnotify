# WxNotify Next-Gen — Admin Tools Dashboard Design

## Context

The `/admin` panel (`AdminPanelProvider`) has sat nearly empty since Phase 0 — `id('admin')`, `path('admin')`, teal color, stock `Dashboard` page, and `discoverResources`/`discoverPages` pointed at `app/Filament/Resources` / `app/Filament/Pages`, neither of which exist on disk. Everything built since Phase 0 (My Locations, Notification Channels, My Alerts) is subscriber-facing, under `/dashboard`.

The next-gen plan's "Admin tooling" section (`FEAT`) names nine staff-only tools, plus one more implied elsewhere in the plan with no literal heading of its own (the Spanish-translation-template editorial review, referred to only as "the same admin-only review screen"):

- **Catalog** — Product Catalog Management
- **Support** — User management & impersonation
- **Accountability** — Audit Log
- **Insight** — Alert-volume statistics
- **Delivery** — Channel delivery success/failure
- **System** — Database notification management
- **Content** — "Did You Know?" content
- **Visibility** — User activity alerting
- **Pipeline** (×2) — Downloader control, and ingested-product search

Cross-checking the plan's prose against the actual codebase state surfaces that these nine (plus Translation Review) split into three buckets, not one uniform "build it" list:

- **Real, buildable now, with real data**: Catalog (the `products` table already exists, seeded with ~50 real NWS products), Content (the dashboard's sidebar render hook already has a static placeholder waiting for exactly this), System (Laravel's own database-notification feature, unused anywhere yet but entirely off-the-shelf).
- **Real, buildable now, but starting empty or partial**: Support (users exist; `stechstudio/filament-impersonate` is installed and completely unwired), Accountability (`spatie/laravel-activitylog` is installed and migrated, but zero models currently log activity — instrumenting them is part of making this resource actually show anything), Insight (real proxy stats exist — alert rule counts, subscriber counts — but no real "alerts sent" volume, since no Alert Engine exists yet), Delivery (the plan itself says the backing table "doesn't exist yet" — its shape is specified clearly enough to build now, empty, ready for the future Alert Engine to write into), Visibility (the rules-configuration UI is real; the real-time dispatch engine reacting to those rules is not — most of the plan's named activity types, like sign-in/out and MFA, aren't captured by `activitylog`'s default model-observation behavior at all).
- **No real backing infrastructure possible**: Pipeline (both halves need a Docker Engine API connection to a pipeline server, and an ingested-product archive, neither of which exist anywhere in this Laravel app — the actual NWWS client is the separate, intentionally-unported `nwws-go-client/` Go project), Translation Review (needs a cached-translation-template pipeline that doesn't exist).

One risk surfaced during research that isn't in the plan text itself: `spatie/laravel-activitylog`'s causer resolution reads `Auth::user()` (`Spatie\Activitylog\Support\CauserResolver::getDefaultCauser()`), and `filament-impersonate` works by swapping the authenticated user for the impersonation session — so without a fix, any activity logged while an admin impersonates a subscriber would attribute to the *subscriber*, silently defeating the plan's own explicit goal that "impersonated actions taken on a subscriber's behalf" stay accountable. `CauserResolver` exposes a `resolveUsing(Closure $callback)` override built for exactly this, and `filament-impersonate`'s `ImpersonateManager` exposes `isImpersonating(): bool` / `getImpersonator(): ?Authenticatable` (reading its own `impersonated_by` session key) — so this has a concrete, verified fix, addressed directly in the Support section below rather than left as an open gap.

Four scoping questions were resolved with the user before this document was written:

1. **Support editing**: read-only relation tables on `UserResource` (locations/alert rules/channels) for diagnosis; actual edits happen by impersonating into `/dashboard` and using the real subscriber-facing forms, not a duplicate admin CRUD surface that could drift from that validation logic.
2. **Authorization**: flat `is_staff` for every tool in this document, including the Pipeline placeholders — no new staff sub-role column now, since Pipeline has no real functionality yet to gate narrowly. Revisit when Pipeline control is actually built against real infrastructure.
3. **Pipeline / Translation Review placeholders**: plain static explanatory pages (what's missing and why) — no non-functional forms or controls that could read as broken.
4. **Content**: wiring `DashboardPanelProvider`'s existing static "Did You Know?" render-hook view to pull from the new admin-managed content is in scope here, not deferred — otherwise this document ships a working admin screen next to a still-hardcoded placeholder it was built to replace.

**This document is the planning/spec pass only** — matching how the Phase 0 Blueprint itself was written before any `/admin` resource code existed. No Filament resources, migrations, or models described below have been built yet; the `### As-Built Notes` section at the end is a placeholder for when implementation happens.

## `/admin` navigation (target shape)

```
Overview                       (stat widgets on the panel's Dashboard page — Insight)
── Operations ──
Pipeline Control               (placeholder — Pipeline)
Ingested Products              (placeholder — Pipeline)
Delivery Log                   (real, empty schema — Delivery)
── Content ──
Product Catalog                (real — Catalog)
"Did You Know?"                (real — Content)
Translation Review             (placeholder)
── Subscribers ──
Users & Impersonation          (real — Support)
── Trust & Audit ──
Audit Log                      (real, read-only — Accountability)
Activity Alert Rules           (real config, no dispatch engine — Visibility)
System Notifications           (real — System)
```

## Catalog — Product Catalog Management

Full CRUD over the existing `products` table (id, code, name, family, has_polygon, risk_level_options, status, sort_order — already seeded with ~50 real NWS VTEC products in `database/migrations/2026_08_30_011307_create_products_table.php`). Table: badges for family/status, a polygon icon column, `->reorderable('sort_order')`, filters on family/status, an `EditAction`, and a `retire` row action.

**Retire workflow**: staff mark a product `Retired` from the table. This must not silently leave subscriber alert rules pointing at a dead product — per the plan, retiring a product automatically disables any subscriber alert rule referencing it, with a notification explaining why. Implemented as a dedicated invokable Action class, `app/Actions/Catalog/RetireProduct.php`, matching how side-effecting business logic already lives in this codebase (`DispatchChannelConfirmationCode`, `GenerateChatwootIdentityHash`) rather than a model boot hook (which would fire on every re-save of an already-retired row, not just the actual transition) or logic inlined in the Table class:

```php
class RetireProduct
{
    public function __invoke(Product $product): int
    {
        $product->update(['status' => ProductStatus::Retired]);

        $affectedRules = $product->alertRules()->where('is_active', true)->get();

        foreach ($affectedRules as $rule) {
            $rule->update(['is_active' => false]);
            $rule->user->notify(new AlertRuleDisabledNotification($product, $rule));
        }

        return $affectedRules->count();
    }
}
```

The table's `retire` action is a thin, confirmed wrapper: `Action::make('retire')->requiresConfirmation()->visible(fn (Product $record) => $record->status !== ProductStatus::Retired)->action(fn (Product $record) => Notification::make()->title("{$count} alert rule(s) disabled")->send())`, mirroring `NotificationChannelsTable`'s existing `resend` action shape exactly.

## Support — User management & impersonation

`UserResource` — form: `name`/`email` (display, not editable here), `Toggle::make('is_staff')`, `Select::make('tier_override')->options(Tier::class)->nullable()->live()`, `Textarea::make('tier_override_reason')->requiredWith('tier_override')` (the first real use of this currently-unused column). Table: name/email, a computed `tier()` badge, `is_staff` icon column, `withCount()` columns for locations/alert rules/notification channels, filters on tier and staff status.

**Locations/AlertRules/NotificationChannels visibility**: read-only `RelationManager`s on `UserResource` — list-only tables (no create/edit/delete actions rendered), for fast diagnostic visibility without duplicating the real subscriber-facing forms (the AlertRule wizard, the Location map picker, the ConnectChannel confirmation flow). Actual edits happen by impersonating into `/dashboard`.

**Impersonation**: no plugin registration needed on `AdminPanelProvider` — `stechstudio/filament-impersonate` auto-registers via Laravel package discovery and its `STS\FilamentImpersonate\Actions\Impersonate` is used directly as a table row action:

```php
Impersonate::make()->redirectTo('/dashboard')
```

added to `UsersTable`'s `recordActions`. The package's own action already checks `method_exists($target, 'canBeImpersonated')` before allowing impersonation of a given user, so add to `User`:

```php
public function canBeImpersonated(): bool
{
    return ! $this->is_staff;
}
```

so staff can't impersonate other staff — picked up automatically, no wiring needed on the action itself.

**Causer-resolver fix** (belongs here, not in Accountability, since it's specifically about how impersonation interacts with audit logging): register a custom causer resolver in `AppServiceProvider::boot()`, using `Spatie\Activitylog\Support\CauserResolver`'s built-in override hook and `filament-impersonate`'s `STS\FilamentImpersonate\ImpersonateManager`:

```php
use Spatie\Activitylog\Support\CauserResolver;
use STS\FilamentImpersonate\ImpersonateManager;

app(CauserResolver::class)->resolveUsing(function () {
    $impersonate = app(ImpersonateManager::class);

    return $impersonate->isImpersonating()
        ? $impersonate->getImpersonator()
        : auth()->user();
});
```

so activity logged during an impersonated session attributes to the admin, not the subscriber being impersonated — directly satisfying the plan's "notably, impersonated actions ... stay accountable" requirement rather than silently missing it.

## Accountability — Audit Log

`ActivityResource` over `Spatie\Activitylog\Models\Activity` directly — `protected static ?string $model = \Spatie\Activitylog\Models\Activity::class;`, no app-owned model wrapping it. `canCreate()`/`canEdit()`/`canDelete()` all return `false`; `getPages()` returns only `index` (optionally `view`, for a full change-diff infolist).

Table columns: `log_name`/`event` (badges), `description`, causer (`getStateUsing(fn ($record) => $record->causer?->name ?? 'System')`, linked to `UserResource::getUrl('edit', ...)` when `$record->causer instanceof User`), subject (`class_basename($record->subject_type).' #'.$record->subject_id`, linked to `ProductResource` when the subject is a `Product`; `Location`/`AlertRule`/`NotificationChannel` subjects are only reachable via `UserResource`'s relation managers, so those stay unlinked plain text), `created_at` (default sort desc). A `ViewActivity` infolist renders `attribute_changes`/`properties` as `Infolists\Components\KeyValueEntry` rather than raw JSON.

**Instrumentation — in scope here, not a follow-up**: nothing currently calls `activity()->log(...)` anywhere in this app. An audit-log screen over a table nothing writes to isn't real, it's a different flavor of placeholder. Add `Spatie\Activitylog\Models\Concerns\LogsActivity` + a `getActivitylogOptions()` override to:

```php
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

public function getActivitylogOptions(): LogOptions
{
    return LogOptions::defaults()
        ->logOnly([...])
        ->logOnlyDirty()
        ->dontSubmitEmptyLogs();
}
```

on `User` (`is_staff`, `tier_override`, `tier_override_reason`), `Product` (`status`, `code`, `name`, `family`), and `AlertRule`/`NotificationChannel`/`Location` (full CRUD — this is also what gives Visibility's rules something real to eventually match against, per the plan's own note that activitylog is "the source of truth behind User activity alerting").

## Insight — Alert-volume statistics

No new tables, no Resource — widgets registered on the admin panel's stock `Filament\Pages\Dashboard` page via `AdminPanelProvider`'s `->widgets([...])`.

Real: `app/Filament/Admin/Widgets/AdminStatsOverview.php` (`Filament\Widgets\StatsOverviewWidget`):

```php
protected function getStats(): array
{
    return [
        Stat::make('Active Alert Rules', AlertRule::where('is_active', true)->count()),
        Stat::make('Subscribers', User::count()),
        Stat::make('Locations Watched', Location::count()),
        Stat::make('Verified Channels', NotificationChannel::whereNotNull('verified_at')->count()),
    ];
}
```

`app/Filament/Admin/Widgets/AlertRulesByCategoryChart.php` (`Filament\Widgets\ChartWidget`, bar) — a real query grouping `alert_rules.category`, honestly labeled as configuration counts, not delivery volume.

Placeholder: an explicit "Alert Volume — arrives with the Alert Engine" note/section rather than a zeroed chart that would read as broken. Drop the stock `FilamentInfoWidget` from this panel (dev-only noise on a real ops screen); keep `AccountWidget`.

## Delivery — Channel delivery success/failure

New table, `delivery_logs`:

```
id
alert_rule_id            — nullable FK, nullOnDelete
notification_channel_id  — nullable FK, nullOnDelete
user_id                  — FK, cascadeOnDelete (denormalized — the plan wants results "broken out by ... subscriber")
channel_type              — string, denormalized so it survives channel deletion
status                    — string: success | failure
error_detail               — nullable text
dispatched_at              — timestamp
timestamps
```

`app/Models/DeliveryLog.php` — plain model, `belongsTo` `User`/`AlertRule`/`NotificationChannel`. `DeliveryLogResource` — read-only (`index` page only, `canCreate`/`canEdit`/`canDelete` false). Columns: `dispatched_at`, `user.email`, `channel_type` (badge), `alert_rule.displayName()`, `status` (badge, green/red), `error_detail` (toggleable, hidden by default). `->emptyStateHeading('No delivery attempts yet')->emptyStateDescription('Populates once the Alert Engine starts logging dispatch attempts.')`.

Built now rather than deferred to a pure static page (unlike Pipeline) because the plan specifies this schema's shape clearly enough to commit to it today, and a real-but-empty resource with an honest empty state costs almost nothing extra while requiring zero rework once the Alert Engine exists to write into it.

## System — Database notification management

`php artisan make:notifications-table` (confirmed present in this Laravel 13 install; `notifications:table` also works as an alias) publishes Laravel's stock `notifications` migration (uuid `id`, `type`, `notifiable_type`/`notifiable_id`, `data` json, `read_at`, timestamps). No custom model — the resource works directly against `Illuminate\Notifications\DatabaseNotification`.

`SystemNotificationResource` — `canCreate()`/`canEdit()` false; **delete is allowed** here, unlike the read-only tools above (the plan's "manageable rather than invisible once sent" implies real cleanup capability, not just visibility). Columns: `type` (badge, `class_basename`), `notifiable` (linked to `UserResource` when it's a `User`), a `data`-derived message preview, `read_at` (boolean icon), `created_at`. A row action toggles read/unread; standard `DeleteAction`/`DeleteBulkAction`.

**Real data source, built in the same pass**: `app/Notifications/AlertRuleDisabledNotification.php` (`via(): array => ['database']`), sent by Catalog's `RetireProduct` action above. This is the one place across all nine tools where an admin screen gets genuinely real, end-to-end data with zero missing infrastructure — worth doing together rather than leaving System empty like Delivery/Insight's placeholder half.

## Content — "Did You Know?"

New table, `did_you_knows`:

```
id
body        — text
is_active   — boolean, default true
sort_order  — unsigned integer, default 0
timestamps
```

`app/Models/DidYouKnow.php` — `#[Fillable(['body', 'is_active', 'sort_order'])]`, `is_active` cast boolean, an `active()` query scope ordered by `sort_order`. Full CRUD `DidYouKnowResource` — the one tool in this document that genuinely needs create/edit, not read-only: `Textarea::make('body')`, `Toggle::make('is_active')`, table with `->reorderable('sort_order')` and a truncated `body` column.

**Render-hook wiring — in scope**: `resources/views/filament/dashboard/did-you-know.blade.php` currently renders hardcoded static markup (per Phase 0's own As-Built notes, deferred "pending this admin tool's existence"). Swap it for a live loop:

```blade
@foreach (\App\Models\DidYouKnow::active()->get() as $fact)
    {{-- existing markup, per-fact --}}
@endforeach
```

Leaving the CRUD built without this wiring would ship a working admin screen next to a still-static, now-pointless placeholder — not acceptable scope-splitting for a change this small.

## Visibility — User activity alerting

New table, `activity_alert_rules`:

```
id
name             — nullable string, admin-facing label
scope            — string: all_subscribers | specific_users
activity_types   — json array (see App\Enums\ActivityAlertType below)
apprise_target   — text, encrypted cast (mirrors notification_channels.apprise_url)
is_active        — boolean, default true
timestamps
```

Plus a pivot, `activity_alert_rule_user`, for the `specific_users` scope. `App\Enums\ActivityAlertType` — a new PHP enum listing every activity type the plan names verbatim: account create, sign-in, sign-out, name change, password change, email change, MFA change, account delete; alert/contact/location/schedule/product-list create/update/delete; misc. settings changes; Daily Forecast enabled.

`app/Models/ActivityAlertRule.php` — `belongsToMany(User::class)`. Full CRUD `ActivityAlertRuleResource` — this configuration surface is genuinely real, even though nothing consumes it yet:

```php
Select::make('scope')->options(['all_subscribers' => 'All subscribers', 'specific_users' => 'Specific subscribers'])->live(),
Select::make('users')->relationship('users', 'email')->multiple()
    ->visible(fn (Get $get) => $get('scope') === 'specific_users'),
CheckboxList::make('activity_types')->options(ActivityAlertType::class),
TextInput::make('apprise_target'),
Toggle::make('is_active'),
```

**Deliberately out of scope**: the real-time dispatch engine — an observer watching activitylog events project-wide (plus net-new auth-event listeners for sign-in/out and MFA, which activitylog's default Eloquent-model observation doesn't capture at all), matching them against active rules, and fanning out through Apprise. Building that half-designed risks a config UI that silently implies live coverage it doesn't have. A persistent notice on `ActivityAlertRuleResource`'s list page states plainly: "Rules are configured but not yet enforced — real-time dispatch requires a separate activity-observer engine, planned separately."

## Pipeline — Downloader control & Ingested product search

Both halves are pure informational placeholders, shaped like the existing `Billing.php` page (a built shell, no model/table, no backend wiring) — per the confirmed decision, static explanatory copy rather than a non-functional form or control panel.

`app/Filament/Admin/Pages/PipelineControl.php` — static rows for NWWS / EMWIN Byteblaster / EMWIN HTTP backup, each a greyed "status unknown" badge plus copy naming the actual missing dependency: a TLS-client-certificate-authenticated connection to the pipeline server's Docker Engine API, which doesn't exist from this Laravel app today (the real NWWS/EMWIN clients live in the separate `nwws-go-client/` project, intentionally not ported here).

`app/Filament/Admin/Pages/IngestedProducts.php` — static copy explaining the missing dependency: an ingested-product archive/index, which doesn't exist yet on either side (Go pipeline or this app).

Both: `resources/views/filament/admin/pages/{pipeline-control,ingested-products}.blade.php`, `Operations` nav group, no `canAccess()`/mode gating beyond the panel's existing staff-only gate (a self-hoster genuinely runs their own pipeline — this isn't a cloud-only concern the way `Billing` is).

## Translation Review (bonus item, no literal `FEAT` heading)

Same treatment as Pipeline: `app/Filament/Admin/Pages/TranslationReview.php`, `Content` nav group, static copy referencing the `DATA` section's cached-translation-template design (LibreTranslate, es-419 boilerplate caching for short-fuse warnings) as the future dependency this screen will review once that pipeline exists.

## Files to create/modify in `wxnotify-dashboard/`

- `app/Providers/Filament/AdminPanelProvider.php` — redirect `discoverResources`/`discoverPages`/`discoverWidgets` from the nonexistent `Filament/Resources`/`Filament/Pages` to `Filament/Admin/{Resources,Pages,Widgets}`, matching the `Filament/Dashboard/...`/`Filament/Kb/...` per-panel convention; register the two Insight widgets; drop `FilamentInfoWidget`.
- `app/Providers/AppServiceProvider.php` — register the impersonation-aware `CauserResolver` override.
- `app/Models/User.php` — add `canBeImpersonated()`; add `LogsActivity` + `getActivitylogOptions()`.
- `app/Models/Product.php`, `app/Models/AlertRule.php`, `app/Models/NotificationChannel.php`, `app/Models/Location.php` — add `LogsActivity` + `getActivitylogOptions()`.
- `app/Models/{DeliveryLog,DidYouKnow,ActivityAlertRule}.php` — new.
- `app/Enums/ActivityAlertType.php` — new.
- `app/Actions/Catalog/RetireProduct.php`, `app/Notifications/AlertRuleDisabledNotification.php` — new.
- `database/migrations/..._create_delivery_logs_table.php`, `..._create_did_you_knows_table.php`, `..._create_activity_alert_rules_table.php`, `..._create_activity_alert_rule_user_table.php`, plus the published `notifications` table migration.
- `app/Filament/Admin/Resources/{Products,Users,ActivityLogs,DeliveryLogs,SystemNotifications,DidYouKnows,ActivityAlertRules}/...` — following the established `Resource / Schemas/{Name}Form / Tables/{Name}Table / Pages/{List,Create,Edit,View}{Name}` convention; read-only resources (`ActivityLogs`, `DeliveryLogs`) omit Create/Edit pages, `SystemNotifications` omits Create/Edit but keeps delete.
- `app/Filament/Admin/Resources/Users/RelationManagers/{Locations,AlertRules,NotificationChannels}RelationManager.php` — read-only.
- `app/Filament/Admin/Widgets/{AdminStatsOverview,AlertRulesByCategoryChart}.php` — new.
- `app/Filament/Admin/Pages/{PipelineControl,IngestedProducts,TranslationReview}.php` + their Blade views under `resources/views/filament/admin/pages/`.
- `resources/views/filament/dashboard/did-you-know.blade.php` — swap static markup for a live `DidYouKnow::active()` loop.

## Verification

- `php artisan route:list --path=admin` shows every new resource/page route.
- Full Pest suite passes (`vendor/bin/pest --compact` inside the Sail container); `vendor/bin/pint --dirty` clean.
- Manual browser pass as a seeded staff user: each of the nine tools loads without error.
- Catalog: retiring a product with an active alert rule visibly disables that rule and produces exactly one `AlertRuleDisabledNotification`, visible in System Notifications.
- Support: the impersonate action logs in as the target subscriber and lands on `/dashboard`; `RedirectAdmins` middleware doesn't bounce the impersonated session back to `/admin`; a `Toggle`'d staff user can't be impersonated (action hidden).
- Accountability: an edit to a `LogsActivity`-enabled model produces a correctly-attributed row; an edit made *while impersonating* attributes to the admin, not the subscriber.
- Content: an edit in `DidYouKnowResource` appears live on `/dashboard`'s sidebar after a page reload; an inactive fact does not appear.
- Pipeline / Translation Review: render their static explanatory copy with no dead links, forms, or buttons that could look broken.
- Non-staff users are denied `/admin` entirely (already covered by the existing `PanelAccessTest`) — re-confirm it still passes after the panel's discovery-path change.

---

## Admin Tools Filament Blueprint (implementation spec)

This section is the specification-ready implementation plan, written against this project's established Filament v5 conventions and verified package internals (`spatie/laravel-activitylog`'s `CauserResolver`/`LogsActivity`/`LogOptions` namespaces and `stechstudio/filament-impersonate`'s `Impersonate` action/`ImpersonateManager` were read directly from `vendor/` this session, not guessed). It supersedes the narrative sections above wherever the two differ on namespaces or exact config chains; those sections still hold for product/UX intent. Nothing in this document has been implemented yet — implementation is a distinct, explicitly-requested next step, matching how Phase 0's own Blueprint preceded its implementation pass.

### Before Running Anything

- **`Filament\Widgets\StatsOverviewWidget`/`Filament\Widgets\ChartWidget` exact v5 API** (constructor/`getStats()`/`getData()` signatures, the `Stat` value object's namespace) — not verified via `search-docs` in this pass; confirm at implementation time rather than assuming v4-era method names carried forward unchanged.
- **`->reorderable()` on a Filament v5 Table** — used throughout this document for `sort_order`-backed resources (Products, "Did You Know?"); confirm the exact method/column-argument signature via `search-docs` before writing the Table classes.
- **`Infolists\Components\KeyValueEntry`** (for Accountability's `attribute_changes`/`properties` display) — confirm exact namespace/class name for Filament v5's Infolists package; this project has not yet built any Infolist beyond `LocationInfolist`, which doesn't use this component.
- **`php artisan make:notifications-table`** — confirmed present in this install (`php artisan list | grep notif` shows it, with `notifications:table` as an alias), safe to run as-is.
- **`Spatie\Activitylog\Models\Concerns\LogsActivity`** and **`Spatie\Activitylog\Support\LogOptions`** — confirmed exact namespaces by reading `vendor/spatie/laravel-activitylog/src/` directly this session (there is no `Spatie\Activitylog\Traits\LogsActivity` alias — using that namespace will fail).
- **`Spatie\Activitylog\Support\CauserResolver`** and **`STS\FilamentImpersonate\ImpersonateManager`** — both confirmed to exist with the exact methods used above (`resolveUsing(Closure)`, `isImpersonating(): bool`, `getImpersonator(): ?Authenticatable`) by reading their source directly.

### 1. Commands

```bash
php artisan make:model DeliveryLog -m --no-interaction
php artisan make:model DidYouKnow -m --no-interaction
php artisan make:model ActivityAlertRule -m --no-interaction
php artisan make:migration create_activity_alert_rule_user_table --no-interaction

php artisan make:notifications-table --no-interaction
php artisan make:notification AlertRuleDisabledNotification --no-interaction

php artisan make:class Actions/Catalog/RetireProduct --no-interaction
php artisan make:enum ActivityAlertType --no-interaction

# Resources — verify exact --panel targeting flag via --help first, per this project's
# own Boost guidelines; these should land under app/Filament/Admin/Resources once
# AdminPanelProvider's discovery paths are updated (see Panels below)
php artisan make:filament-resource Product --no-interaction
php artisan make:filament-resource User --no-interaction
php artisan make:filament-resource DeliveryLog --no-interaction
php artisan make:filament-resource DidYouKnow --no-interaction
php artisan make:filament-resource ActivityAlertRule --no-interaction
# ActivityResource and SystemNotificationResource point at package/framework models
# (Spatie\Activitylog\Models\Activity, Illuminate\Notifications\DatabaseNotification)
# rather than an app model — verify make:filament-resource supports an --model= flag
# targeting a non-app-namespaced class via --help before running these two

php artisan make:filament-page PipelineControl --no-interaction
php artisan make:filament-page IngestedProducts --no-interaction
php artisan make:filament-page TranslationReview --no-interaction

php artisan make:filament-widget AdminStatsOverview --stats-overview --no-interaction
php artisan make:filament-widget AlertRulesByCategoryChart --chart --no-interaction

php artisan migrate --no-interaction
```

### 2. Models

```
Model: DeliveryLog (new, plain)
  Table: delivery_logs (id, alert_rule_id nullable FK null-on-delete, notification_channel_id
    nullable FK null-on-delete, user_id FK cascade, channel_type string, status string,
    error_detail nullable text, dispatched_at timestamp, timestamps)
  Relations: user(): BelongsTo, alertRule(): BelongsTo, notificationChannel(): BelongsTo

Model: DidYouKnow (new)
  #[Fillable(['body', 'is_active', 'sort_order'])]
  Table: did_you_knows (id, body text, is_active boolean default true,
    sort_order unsigned int default 0, timestamps)
  Cast: is_active => boolean
  Scope: active() — where is_active = true, orderBy sort_order

Model: ActivityAlertRule (new)
  Table: activity_alert_rules (id, name nullable string, scope string, activity_types json,
    apprise_target text, is_active boolean default true, timestamps)
  Cast: activity_types => array, apprise_target => encrypted, scope => a new
    App\Enums\ActivityAlertScope enum (all_subscribers | specific_users), is_active => boolean
  Relation: users(): BelongsToMany (pivot activity_alert_rule_user)

Model: User (modify)
  Add trait: Spatie\Activitylog\Models\Concerns\LogsActivity
  Add method:
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['is_staff', 'tier_override', 'tier_override_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
  Add method:
    public function canBeImpersonated(): bool
    {
        return ! $this->is_staff;
    }
  Docs: https://spatie.be/docs/laravel-activitylog, https://filamentphp.com/plugins/stechstudio-impersonate

Model: Product, AlertRule, NotificationChannel, Location (modify)
  Add trait: Spatie\Activitylog\Models\Concerns\LogsActivity
  Add method: getActivitylogOptions() — LogOptions::defaults()->logOnlyDirty()->dontSubmitEmptyLogs()
    (no ->logOnly() restriction — full-attribute CRUD logging per the plan's "full CRUD on
    alerts, contacts, locations" requirement for Visibility's future rule-matching)
```

### 3. Panels

```
Panel: Admin (existing — modify)
  Location: App\Providers\Filament\AdminPanelProvider
  Docs: https://filamentphp.com/docs/5.x/panel-configuration

  Changes from current file:
    ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
      → ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
    ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
      → ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
    Add: ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
    Add: ->widgets([AdminStatsOverview::class, AlertRulesByCategoryChart::class, AccountWidget::class])
      (drop FilamentInfoWidget)
    Keep: ->id('admin'), ->path('admin'), ->colors(['primary' => Color::Teal]), ->login(),
      existing middleware/authMiddleware — unchanged

  Note: nav groups are set per-resource/page via the existing established convention
  (protected static string|UnitEnum|null $navigationGroup = '...'; on each Resource/Page
  class, matching 'Alerting' on the dashboard resources) — no new NavigationGroup-object
  API is introduced on the panel provider itself.

  Note: stechstudio/filament-impersonate needs no ->plugins() entry — it registers via
  Laravel package discovery (already true today) and its Impersonate action is used
  directly inside UsersTable, not registered on the panel.
```

### 4. Resources & Pages

```
Resource: ProductResource
  Location: App\Filament\Admin\Resources\Products
  Model: App\Models\Product
  Nav group: Content

  Form:
    TextInput::make('code')->required()->unique(ignoreRecord: true)
    TextInput::make('name')->required()
    Select::make('family')->options(ProductFamily::class)->required()
    Toggle::make('has_polygon')
    TagsInput::make('risk_level_options')->nullable()
    Select::make('status')->options(ProductStatus::class)->default(ProductStatus::Draft)->required()

  Table:
    TextColumn: code, name
    TextColumn: family — badge
    TextColumn: status — badge, color: draft=gray, active=success, retired=danger
    IconColumn: has_polygon
    TextColumn: sort_order
    ->reorderable('sort_order')
    Filters: SelectFilter family, SelectFilter status
    Actions: EditAction, retire (Action::make('retire')->requiresConfirmation()
      ->visible(fn (Product $record) => $record->status !== ProductStatus::Retired)
      ->action(function (Product $record) {
          $count = app(RetireProduct::class)($record);
          Notification::make()->title("{$count} alert rule(s) disabled")->success()->send();
      }))

  Pages: index (List), create (Create), edit (Edit)

Resource: UserResource
  Location: App\Filament\Admin\Resources\Users
  Model: App\Models\User
  Nav group: Subscribers

  Form:
    TextInput::make('name')->disabled()
    TextInput::make('email')->disabled()
    Toggle::make('is_staff')
    Select::make('tier_override')->options(Tier::class)->nullable()->live()
    Textarea::make('tier_override_reason')->requiredWith('tier_override')

  Table:
    TextColumn: name, email
    TextColumn: tier — getStateUsing(fn (User $record) => $record->tier()->value), badge
    IconColumn: is_staff
    TextColumn: locations_count, alert_rules_count, notification_channels_count
      (getEloquentQuery(): withCount(['locations', 'alertRules', 'notificationChannels']))
    TextColumn: created_at
    Filters: SelectFilter tier_override, TernaryFilter is_staff
    Actions: EditAction, STS\FilamentImpersonate\Actions\Impersonate::make()->redirectTo('/dashboard')

  RelationManagers: LocationsRelationManager, AlertRulesRelationManager,
    NotificationChannelsRelationManager — each ->canCreate(false), table-only,
    no EditAction/DeleteAction registered

  Pages: index (List), edit (Edit) — no create page, users are never admin-created here

Resource: ActivityResource
  Location: App\Filament\Admin\Resources\ActivityLogs
  Model: Spatie\Activitylog\Models\Activity
  Nav group: Trust & Audit
  Override: canCreate() => false, canEdit() => false, canDelete() => false

  Table:
    TextColumn: log_name — badge
    TextColumn: event — badge
    TextColumn: description
    TextColumn: causer — getStateUsing(fn ($r) => $r->causer?->name ?? 'System'),
      url(fn ($r) => $r->causer instanceof User ? UserResource::getUrl('edit', ['record' => $r->causer]) : null)
    TextColumn: subject — getStateUsing(fn ($r) => class_basename($r->subject_type).' #'.$r->subject_id),
      url(fn ($r) => $r->subject_type === Product::class ? ProductResource::getUrl('edit', ['record' => $r->subject_id]) : null)
    TextColumn: created_at — sortable, default sort desc
    Filters: SelectFilter log_name, SelectFilter event, Filter (date range) on created_at

  Pages: index (List), view (View — infolist rendering attribute_changes/properties as KeyValueEntry)

Resource: DeliveryLogResource
  Location: App\Filament\Admin\Resources\DeliveryLogs
  Model: App\Models\DeliveryLog
  Nav group: Operations
  Override: canCreate() => false, canEdit() => false, canDelete() => false

  Table:
    TextColumn: dispatched_at
    TextColumn: user.email
    TextColumn: channel_type — badge
    TextColumn: alert_rule.displayName (getStateUsing, nullable-safe)
    TextColumn: status — badge, success=green/failure=red
    TextColumn: error_detail — toggleable(isToggledHiddenByDefault: true)
    ->emptyStateHeading('No delivery attempts yet')
    ->emptyStateDescription('Populates once the Alert Engine starts logging dispatch attempts.')
    Filters: SelectFilter status, SelectFilter channel_type

  Pages: index (List) only

Resource: SystemNotificationResource
  Location: App\Filament\Admin\Resources\SystemNotifications
  Model: Illuminate\Notifications\DatabaseNotification
  Nav group: Trust & Audit
  Override: canCreate() => false, canEdit() => false

  Table:
    TextColumn: type — badge, formatStateUsing(fn ($state) => class_basename($state))
    TextColumn: notifiable — getStateUsing, url() to UserResource when notifiable is a User
    TextColumn: data — getStateUsing(fn ($r) => Str::limit($r->data['message'] ?? '', 60))
    IconColumn: read_at — boolean
    TextColumn: created_at
    Actions: Action::make('toggleRead')->action(fn ($record) => $record->read_at
        ? $record->markAsUnread() : $record->markAsRead()),
      DeleteAction
    BulkActions: DeleteBulkAction

  Pages: index (List) only

Resource: DidYouKnowResource
  Location: App\Filament\Admin\Resources\DidYouKnows
  Model: App\Models\DidYouKnow
  Nav group: Content

  Form: Textarea::make('body')->required(), Toggle::make('is_active')->default(true)

  Table:
    TextColumn: body — limit(60)
    IconColumn: is_active
    TextColumn: sort_order
    ->reorderable('sort_order')
    Actions: EditAction, DeleteAction

  Pages: index (List), create (Create), edit (Edit)

Resource: ActivityAlertRuleResource
  Location: App\Filament\Admin\Resources\ActivityAlertRules
  Model: App\Models\ActivityAlertRule
  Nav group: Trust & Audit

  Form:
    TextInput::make('name')->nullable()
    Select::make('scope')->options(['all_subscribers' => 'All subscribers', 'specific_users' => 'Specific subscribers'])->live()->required()
    Select::make('users')->relationship('users', 'email')->multiple()
      ->visible(fn (Get $get) => $get('scope') === 'specific_users')
    CheckboxList::make('activity_types')->options(ActivityAlertType::class)->columns(2)
    TextInput::make('apprise_target')->required()
    Toggle::make('is_active')->default(true)

  Table: name, scope (badge), activity_types (count badge), is_active (icon), Actions: EditAction, DeleteAction

  List page: a persistent Placeholder/banner reading "Rules are configured but not yet
  enforced — real-time dispatch requires a separate activity-observer engine, planned separately."

  Pages: index (List), create (Create), edit (Edit)

Page: PipelineControl
  Location: App\Filament\Admin\Pages\PipelineControl
  Nav group: Operations
  Content: static — three rows (NWWS, EMWIN Byteblaster, EMWIN HTTP backup), each a
    disabled/grey status badge plus copy explaining the missing dependency (a
    TLS-client-cert-authenticated Docker Engine API connection to the pipeline server)

Page: IngestedProducts
  Location: App\Filament\Admin\Pages\IngestedProducts
  Nav group: Operations
  Content: static — copy explaining the missing dependency (an ingested-product archive/index)

Page: TranslationReview
  Location: App\Filament\Admin\Pages\TranslationReview
  Nav group: Content
  Content: static — copy explaining the missing dependency (cached translation templates,
    referencing the DATA section's LibreTranslate/es-419 caching design)
```

### 5. Authorization

```
Panel: admin
  Authorization: unchanged — canAccessPanel() on User returns is_staff for every
  resource/page in this document. No per-resource canView()/canAccess() overrides,
  no new roles/permissions system. Confirmed flat by the user, since Pipeline (the
  one tool where a narrower gate might eventually matter) stays a static placeholder
  in this pass with no real functionality to protect yet.

  UserResource's Impersonate action additionally checks canBeImpersonated() on the
  target (staff can't impersonate staff) — a resource-level guard, not a panel-level one.
```

### 6. Widgets

```
Widget: AdminStatsOverview
  Location: App\Filament\Admin\Widgets\AdminStatsOverview
  Extends: Filament\Widgets\StatsOverviewWidget
  Registered: AdminPanelProvider ->widgets([...])
  Stats: Active Alert Rules (AlertRule::where('is_active', true)->count()),
    Subscribers (User::count()), Locations Watched (Location::count()),
    Verified Channels (NotificationChannel::whereNotNull('verified_at')->count())

Widget: AlertRulesByCategoryChart
  Location: App\Filament\Admin\Widgets\AlertRulesByCategoryChart
  Extends: Filament\Widgets\ChartWidget
  Type: bar
  Data: AlertRule::query()->select('category', DB::raw('count(*) as count'))
    ->groupBy('category')->pluck('count', 'category')
  Heading: "Alert Rules by Category" — deliberately not "Alerts Sent", to avoid
  implying dispatch volume this doesn't track
```

### 7. Tests

```
ProductResourceTest:
  - staff can list/create/edit products regardless of status
  - retiring a product sets status = Retired
  - retiring a product with an active alert rule referencing it: disables that rule
    (is_active = false), creates exactly one AlertRuleDisabledNotification for its user
  - retiring a product with zero affected rules: no error, no stray notifications

UserResourceTest:
  - editing tier_override requires tier_override_reason (validation error when omitted,
    if that constraint is kept as specified)
  - tier_override + tier_override_reason both persist together
  - relation managers (locations/alert rules/channels) render only the target user's own
    records, with no create/edit/delete actions present
  - Impersonate action is hidden when the target user's is_staff = true
    (assertActionHidden or the resource-equivalent per this project's established pattern)
  - impersonating a subscriber authenticates as that subscriber and redirects to /dashboard

ActivityLogResourceTest:
  - index renders with no Create/Edit/Delete actions available
  - an update to a LogsActivity-enabled model (e.g. Product::update(['status' => ...]))
    produces one Activity row with the correct causer/event/subject
  - an update made during an impersonated session (auth()->user() is the subscriber,
    session has impersonated_by set) attributes causer to the impersonating admin,
    not the subscriber — the highest-value test in this document, given the plan's
    explicit emphasis on this exact failure mode

AdminWidgetsTest:
  - AdminStatsOverview's rendered stat values match direct AlertRule::count()/User::count()
    etc. queries against seeded data
  - /admin loads without error for a staff user

DeliveryLogResourceTest:
  - empty state renders correctly with zero rows (today's real condition)
  - seeded DeliveryLog rows (factory) are visible and filterable by status
  - no create/edit UI is exposed anywhere on this resource

SystemNotificationResourceTest:
  - a seeded DatabaseNotification is visible in the table
  - the toggleRead action flips read_at correctly in both directions
  - DeleteAction removes the row
  - integration: retiring a Product with an active alert rule produces a notification
    visible here (ties directly to ProductResourceTest's retire assertions)

DidYouKnowResourceTest:
  - full CRUD, including reorder (sort_order changes persist) and the is_active toggle
  - a feature test hitting GET /dashboard asserts an active fact's body appears in the
    response and an inactive fact's body does not

ActivityAlertRuleResourceTest:
  - full CRUD
  - scope = specific_users correctly persists attached users via the pivot;
    scope = all_subscribers doesn't require any user attached
  - activity_types round-trips as an array through the CheckboxList
  - no dispatch/delivery assertions anywhere — explicitly out of scope

Pipeline / Translation Review pages:
  - covered by the existing tests/Feature/PanelAccessTest.php (staff reach /admin,
    non-staff don't) — no additional functional assertions needed for static pages
```

### 8. As-Built Notes (post-implementation)

*(Nothing has been implemented yet — this section is intentionally empty. Fill in after building and browser-verifying the above, matching the style of the equivalent section in `docs/phase-0-filament-blueprint.md`: corrections, package surprises, and debugging war-stories discovered during real implementation, not restatements of the spec above.)*
