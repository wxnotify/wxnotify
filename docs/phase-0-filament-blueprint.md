# WxNotify Next-Gen — Phase 0 Filament Dashboard Design

## Context

WxNotify is being rebuilt from a working "current-gen" Laravel/Filament SaaS into a next-gen platform (full plan: `wxnotify-nextgen-plan.html`). Phase 0 ("Foundation") is the first roadmap milestone: scaffold the new Laravel/Filament dashboard, wire in the auth stack (2FA, SSO, passkeys), and stand up the Chatwoot support widget. The `wxnotify-dashboard/` project is a bare Laravel 13 / Filament 5 scaffold at this point — one default `AdminPanelProvider` (Amber, path `admin`), a stock `User` model, no resources.

**Scope correction (post-design-approval):** the existing `nwws-go-client/` (a separate, working Go project — XMPP MUC client, ~14,700 lines including a patched library fork) is **not** being ported to PHP; it stays as-is and continues running unchanged. The EMWIN client is a distinct piece of work from NWWS, still to be fleshed out, and is deferred to **Phase 1** rather than Phase 0. **Spark billing has also moved to Phase 2 (Monetization)**, matching the source plan's own roadmap phase for it — Phase 0's Billing page stays a placeholder shell (built, gated to `cloud` mode, no Spark wiring) until then. Phase 0's actual remaining scope is therefore: the panel shells, the auth stack, and Chatwoot — no data-pipeline porting work, no billing integration.

**Second scope correction (mid-implementation):** two planned packages turned out to be abandoned on Packagist. `laragear/webauthn` — its author now recommends `laravel/passkeys`, which turns out to be a **browser-side JS client only** (no server routes, no Filament integration); a real passkey implementation still needs a server-side WebAuthn library picked and wired in, so this stays an open item, not swapped-and-done. `z3d0x/filament-logger` has no suggested replacement at all — `spatie/laravel-activitylog` itself (already installed) is fine and unaffected; only the Filament *admin UI* over its `Activity` model needs a different path. Since that UI is Phase 1+ work (the Audit Log admin screen was never Phase 0 scope), the fix is simple: build a plain read-only Filament Resource over `Spatie\Activitylog\Models\Activity` when that phase arrives, instead of installing a package for it.

**Filament Blueprint gate:** the user intends to purchase and install the `filament/blueprint` Boost extension (`SETUP` in the source plan) before any Filament-specific code gets written — panel providers, pages, resources, forms. Until that go-ahead is given, implementation is limited to plain Laravel pieces that don't touch Filament (config, models, migrations, validation rules, plain Actions/service classes).

Before writing any Phase 0 code, this session ran a brainstorming/design pass (via the `superpowers:brainstorming` skill, with a visual-companion browser session for the layout/navigation questions) to settle the dashboard's overall shape — panel architecture, navigation, branding, and the alert-creation UX — so Phase 0's scaffolding lands in the right structure instead of being reshuffled once real features start landing in Phases 1–3. The rest of this document is the resulting design, intended to be built directly (not a menu of options).

**Key resolved deviation from the source plan:** the nextgen plan describes subscriber-facing alert setup as location-centric ("each saved location's own page" holds forecast + alert-rule config) with `FEAT`'s five matching modes implicitly organizing navigation. Design discussion surfaced a cleaner alternative: alert rules become a single flat resource created through a category-first wizard, decoupled from Locations. This still satisfies every requirement in `FEAT`/`DATA` (all five matching modes, all six notification categories, per-rule template/channel/quiet-hours) — it just organizes the *UI*, not the underlying rule model, differently than the plan's prose implies. Locations becomes a simpler address-book resource.

## Panel Architecture

Two Filament panels, not one:

- **`/dashboard`** — subscriber-facing panel. Default panel for the app. Primary color: **blue** — a deliberate departure from the marketing site's orange `--accent` (exact hex TBD at implementation; default to Filament's stock Blue palette unless a specific brand blue is supplied). Add a **new** `App\Providers\Filament\DashboardPanelProvider`, `id('dashboard')`, `path('dashboard')`, `->default()`.
- **`/admin`** — staff-only panel (rename the existing `AdminPanelProvider`'s color/id stay as-is: `id('admin')`, `path('admin')`, but drop `->default()` since `/dashboard` is now default). Primary color: brand teal (`#0e6b74` family, matching `--accent2`). The color split is deliberate: it's a passive "which panel am I in" signal, useful when staff are impersonating.

Both panels: system-preference dark mode by default (`->darkMode()`), user-toggleable — matches the marketing site's existing `prefers-color-scheme` behavior. Both driven off the shared Filament v5 / Laravel 13 / PHP 8.4 baseline already pinned in the source plan's `SETUP` section.

Gate access to `/admin` by a staff flag/role on `User` (e.g. a `is_staff` boolean or a role via a lightweight authorization package) — not scoped further here since no admin *resources* ship in Phase 0, only the panel shell and auth.

## Self-host mode flag (added in Phase 0)

Add `config('wxnotify.mode')` = `cloud` | `selfhost` (env-driven: `WXNOTIFY_MODE`). Both panel providers read it to conditionally register cloud-only nav items (Billing, Chatwoot widget, cross-tenant impersonation) when `selfhost`. Nothing self-hosted exists until Phase 4, but the seam goes in now rather than being retrofitted across three phases of resources.

## `/dashboard` navigation (subscriber panel)

```
Dashboard                              (landing page)
── Alerting ──
My Alerts                              (flat list, all categories — see below)
My Locations                           (address book + view-only 7-day forecast)
Notification Channels
Alert Templates                        (Plus/Pro — visible, locked)
── Tools ──
Traccar Servers                        (Plus/Pro — visible, locked)
Radar Place Files
Developer / API Keys                   (Plus/Pro — visible, locked)
── Account ──
Billing                                (custom Page wrapping Spark's Livewire component)
My Profile                             (native Filament v5 profile page)
[sidebar footer, every page]  "Did You Know?" rotating panel
[lower-left corner, floating]  Guava Knowledge Base link (plugin default)
```

**Tier-gated resources** (Alert Templates, Traccar Servers, Developer/API Keys): always visible in nav for Free subscribers. Clicking opens an upgrade-prompt page (feature explanation + link to Billing) instead of the real resource — never hidden outright, since a Free subscriber discovering the feature exists is part of the upsell path.

### My Alerts — the alert-creation wizard

One resource, one list, one "New Alert" action, regardless of category. The wizard's **first step is category**, not product — this is what avoids forcing every category through the same shape:

1. **Category** — Weather Alerts / Daily Weather Summary / Threshold Alerts / SPC & WPC Alerts / Lightning Alerts / Forecast Discussions (mirrors `FEAT`'s six notification categories).
2. **Category-specific steps**, e.g.:
   - *Weather Alerts / SPC & WPC Alerts*: product (grouped) → matching mode (nationwide / by my location / by county-zone / by polygon / by WFO), revealing a location or WFO picker as needed → risk-level filter if applicable.
   - *Threshold Alerts*: variable → direction/threshold → timeframe → location.
   - *Daily Weather Summary*: location → delivery time.
   - *Lightning Alerts*: location → radius (enforced minimum).
   - *Forecast Discussions*: WFO (auto-suggested from a location via CWA boundary lookup).
3. **Channel(s)** — from the subscriber's configured Notification Channels.
4. **Quiet hours** (optional).
5. Template selection folds in as a step for Plus/Pro subscribers with custom templates (`FEAT`'s Custom alert templates) — system default otherwise.

**Inline creation of Locations and Channels:** a subscriber with zero saved locations (or zero configured channels) shouldn't have to abandon the wizard to go create one. The Location and Channel `Select` fields use Filament's built-in create-option-in-modal capability (`->createOptionForm([...])` / `->createOptionAction()`) — picking "+ Add a location" (or "+ Add a channel") opens the real creation form in a modal without leaving the wizard step, and the new record is auto-selected on save. When the relevant list is empty, the select's placeholder/helper text should nudge toward that action rather than showing a blank, unexplained dropdown.

Implement as a Filament **Wizard** form component on the resource's Create/Edit pages. Underlying data model is still the general-purpose rule (matching mode, location(s), product filter, channel(s), template) from `FEAT` for the product/matching-mode categories; Threshold and Daily Summary are their own row shapes (no product/matching-mode fields) sharing the same `alert_rules`-family table via nullable columns or a discriminated sub-table — worth a quick modeling pass at implementation time, not pinned further here.

### My Locations

Plain CRUD resource: stationary (address/city-state/ZIP/lat-lon) or mobile (Traccar-backed or, later, native-app-backed). Detail page shows the location's own 7-day forecast (read-only — sourced from the same NWS gridpoint data `DATA` describes). No alert configuration lives here anymore.

**Stationary location picker.** Diverges from the source plan's Google Maps assumption — uses **Leaflet** instead, via `fahiem/filament-pinpoint` as the primary plugin candidate to evaluate first (its built-in click-to-set marker, draggable marker, current-location button, and reverse geocoding map closely onto the requirements below; `eduardo-ribeiro-leaflet` — Beta, broader Leaflet toolkit with clusters/GeoJSON — is the fallback if Pinpoint doesn't hold up in a real trial). Three input paths, always kept in sync to one lat/lon + address:

1. **One smart text field** (not separate fields per format) accepting a US street address, City/State, ZIP, or a raw `lat, lon` pair — format auto-detected client-side (a coordinate-looking pattern skips geocoding entirely; anything else is forward-geocoded).
2. **"Use my location" button** — browser Geolocation API, treated the same as a manual pin placement (reverse-geocoded into the text field), with a visible "approximate" note since browser geolocation accuracy varies.
3. **The Leaflet map itself** — tap to place a marker, drag an existing marker to move it, standard zoom/pan controls.

Any of the three updates the other two: typing an address forward-geocodes and moves the marker; tapping/dragging the marker reverse-geocodes into the text field.

- **Forward geocoding** (address/city-state/ZIP → coordinates): **US Census Bureau Geocoding Services API** — free, federal, no API key, matches the "US-based address" constraint and the plan's existing free-federal-data posture (it doesn't do full reverse-to-street-address lookups, which is why reverse uses a different service below). When the geocoder returns multiple candidates for an ambiguous input (e.g. "123 Main St" with no city), show a dropdown of candidates and require the subscriber to pick one rather than silently taking the top match — zero matches is an inline error, not a guess. Matches the plan's existing no-false-confidence instinct (Heads Up, Lightning Alerts) — worth the extra click for a life-safety location.
- **Reverse geocoding** (marker tap/drag/geolocate → address text): **Nominatim** (OpenStreetMap) to start — low, bursty call volume (only fires when a subscriber is actively placing a pin), realistic to run within Nominatim's usage policy without self-hosting; revisit if volume ever demands it.
- **Map tiles**: a commercial free tier (MapTiler or Stadia Maps) to start, rather than OSM's own tile server (whose usage policy disallows sustained commercial use) or self-hosting — same "start free, revisit at scale" treatment the source plan already gives Healthchecks and OpenRouteService. Self-hosted PMTiles on the existing Backblaze B2 store is the natural upgrade path later if usage outgrows the free tier, but building that now would be premature.

### Notification Channels

Three-step create/edit flow, same shape for every Apprise-backed channel:

1. **Choose a channel** — an icon/radio picker grid (brand icons via [Simple Icons](https://simpleicons.org), generic fallback for anything it doesn't cover), searchable given the catalog's size. Every channel tile is always visible, including ones the subscriber's tier can't use yet — a locked tile shows a lock badge and opens an upgrade explainer on click, the same visible-but-locked pattern used for gated nav items rather than filtering the grid down.
2. **Channel-specific form** — fields specific to the chosen channel (e.g. Discord: webhook URL; Telegram: bot token + chat ID; Twilio: Account SID/Auth Token/numbers). The subscriber never sees or edits a raw Apprise URL in the guided path; it's built from their form input.
3. **Confirmation code** — before the channel is usable, WxNotify dispatches a real Apprise message to the URL just built, containing a short code, and the subscriber types that code back into WxNotify. Same mechanism for every channel type, Custom Webhooks included (the subscriber checks their own endpoint's received payload/logs for the code) — one verification model everywhere, rather than a webhook-specific auto-verify-on-2xx shortcut. This is what actually proves the config delivers, not just that the form validated. The channel record exists in a pending/unverified state until this step completes.

**Expert mode**: a toggle on step 1 that replaces steps 1–2 with a single raw-Apprise-URL input, for subscribers who'd rather not use the guided form. Available to cloud Plus/Pro subscribers and to every tier on self-hosted instances (not cloud Free — raw URLs can encode BYO-paid-service credentials, already a Plus/Pro concept elsewhere in the plan). Still goes through the same real-dispatch confirmation-code step as the guided path — a pasted URL is exactly the input most likely to have a typo.

**Tier gating**, matching `TIER` exactly rather than a simplified "push channels only" rule: Email is unconstrained at every tier; Free additionally gets exactly **one** no-cost channel (any single pick from ntfy/Web Push/Discord/Slack/Telegram/Pushover/MS Teams/Custom Webhooks) — once that slot is filled, the remaining no-cost tiles lock too (subscriber can delete/swap their existing pick for a different one, but can't hold two at once); BYO-key paid channels (Twilio, BulkVS, WhatsApp, PagerDuty, etc.) stay locked entirely until Plus; Plus/Pro have no count limit.

**Architecture**: a `ChannelType` PHP class per Apprise service owns the form schema and the Apprise-URL-builder logic (this can't be database-driven — each one is real, bespoke mapping code). A `channel_types` database table (slug, enabled, min_tier, sort order) — mirroring the Product Catalog's admin-editable pattern elsewhere in the plan — controls what appears in the picker and at what tier, so promoting a channel or disabling one doesn't require a deploy, and there's one source of truth instead of tier logic duplicated across the class and the picker.

### My Profile

**Correction post-Blueprint-install:** the original design assumed the third-party `jeffgreco13/filament-breezy` plugin for this. Filament v5 core now ships the same capability natively — `->profile()` for the page itself and `->multiFactorAuthentication([AppAuthentication::make()])` for TOTP 2FA — so Breezy is dropped entirely; one fewer dependency, first-party maintained. Passkey/WebAuthn (`laragear/webauthn` or similar) isn't covered by either option and still needs its own integration, added to a custom `EditProfile` page alongside the native fields. SSO account linking uses `dutchcodingcompany/filament-socialite`, which is expected to surface its own profile UI — confirm its exact integration point against its own docs at implementation time rather than assuming Breezy-shaped hooks. Native API key generation was never part of this page (it's Breezy's bundled Sanctum panel we were avoiding); it stays the separate, Plus/Pro-gated Developer/API Keys page either way.

### Developer / API Keys

Separate page (not part of the profile page) specifically because API key generation is Plus/Pro-gated per `FEAT`'s Public API section — Free subscribers see the locked/upgrade-prompt version described above.

### Billing

Custom Filament Page (e.g. `App\Filament\Dashboard\Pages\Billing`) rendering Spark's subscription-management Livewire component inside the panel's normal chrome. Registration itself stays Filament-native and creates a Free-tier account with no payment step — Spark/Stripe/Paddle only enters the flow when a subscriber actually upgrades from this page.

### Registration, password policy & account activation

Registration is a custom Filament `Register` page (extends `Filament\Auth\Pages\Register` — corrected namespace, confirmed via Filament v5 docs) on the `/dashboard` panel, not the stock one:

- **Fields**: full name, email, password, password confirmation.
- **Password policy**, enforced via Laravel's `Password` validation rule (`Password::min(8)->letters()->mixedCase()->numbers()->symbols()`): minimum 8 characters, at least one lowercase letter, one uppercase letter, one number, and one special character (`@$!%*?&`); confirmation must match.
- **Live requirements checklist**: a small Alpine.js-driven component below the password field, wired to the input's value client-side (no server round-trip) — each rule (length, lowercase, uppercase, number, special character, match) shows a check/cross that updates as the subscriber types. Implemented as a custom Filament form component/view, not a stock field.
- **Bot protection**: Cloudflare Turnstile widget on the registration form, verified server-side against Cloudflare's `siteverify` API as part of form validation (a custom validation rule) before an account is created.
- **Email verification required**: enable Filament's `->emailVerification()` on the `/dashboard` panel; `User` implements `MustVerifyEmail`. An account exists immediately on registration but is treated as inactive (no dashboard access beyond the "please verify" screen) until verified — standard Filament/Laravel behavior, not custom-built.
- **Forgot / reset password**: enable Filament's `->passwordReset()` on the `/dashboard` panel, which provides both routes out of the box — no custom build needed.

### Chatwoot widget

Inject via a Filament render hook (`PanelsRenderHook::BODY_END` or equivalent) in the `/dashboard` panel provider only — not `/admin`. For a logged-in subscriber, generate Chatwoot's signed identity-validation payload server-side (small dedicated service/Action, e.g. `App\Actions\Support\GenerateChatwootIdentityHash`) and pass it into the widget script so conversations carry the subscriber's real identity and tier into Slack. Skipped entirely when `config('wxnotify.mode') === 'selfhost'`.

### Dashboard landing page

Stat widgets (active alert rules, locations watched, alerts in last 30 days), a "New Alert" quick-action, and a recent-alerts-sent table. These read from data that doesn't exist until Phase 1's alerting MVP ships — build the widgets against real Eloquent queries, but the landing page will legitimately show zeros/empty-states through the end of Phase 0.

## `/admin` navigation (staff panel)

```
Overview                       (alert-volume stats — Insight)
── Operations ──
Pipeline Control               (start/stop NWWS, EMWIN×2 — Pipeline)
Ingested Products              (search/browse archive — Pipeline)
Delivery Log                   (per-attempt success/failure — Delivery)
── Content ──
Product Catalog                (Catalog)
"Did You Know?"                (Content — CRUD backing the sidebar widget)
Translation Review             (DATA — Spanish template QA)
── Subscribers ──
Users & Impersonation          (Support)
── Trust & Audit ──
Audit Log                      (Accountability — spatie/laravel-activitylog + z3d0x/filament-logger)
Activity Alert Rules           (Visibility)
System Notifications           (System)
```

None of these resources are Phase 0 work (they land as their owning features ship in later phases) — this is the target shape the `/admin` `PanelProvider` should be able to grow into without restructuring.

**Users & Impersonation** gets one Phase-0-relevant requirement now: a **"Change Tier"** admin action (Free/Plus/Pro), independent of the subscriber's actual Spark subscription — this is how staff "gift" a paid tier. Implication for the data model: tier resolution must check an admin-set override before falling back to real Spark subscription status (e.g. a nullable `tier_override` + `tier_override_reason` on `users`, or a small `account_tier_overrides` table if history/expiry ever matters — start with the simpler nullable-column form). Tier-change actions flow through the existing audit-logging package automatically. `stechstudio/filament-impersonate` handles the impersonation half directly.

## Files to create/modify in `wxnotify-dashboard/`

- `app/Providers/Filament/AdminPanelProvider.php` — existing file; drop `->default()`, set primary color to teal, keep `id`/`path` as `admin`.
- `app/Providers/Filament/DashboardPanelProvider.php` — new (via `php artisan make:filament-panel dashboard`); `id('dashboard')`, `path('dashboard')`, `->default()`, `Color::Blue`, `->registration(Register::class)`, `->emailVerification()`, `->passwordReset()`, `->profile(EditProfile::class)`, `->multiFactorAuthentication([AppAuthentication::make()])`, sidebar-footer + Chatwoot render hooks. Dark mode is on by default — no explicit call needed.
- `config/wxnotify.php` — new; `mode` key reading `WXNOTIFY_MODE` env var.
- `app/Models/User.php` — add `tier` resolution (override-first, Spark-fallback), `is_staff` flag, `MustVerifyEmail` *(done this session)*, plus `Filament\Models\Contracts\FilamentUser` + `canAccessPanel()` (staff-only gate on `/admin` — see Blueprint spec below).
- `app/Filament/Dashboard/Pages/Auth/Register.php` — custom registration page (name field, password policy, live checklist, Turnstile). Extends `Filament\Auth\Pages\Register`.
- `app/Filament/Dashboard/Pages/Auth/EditProfile.php` — extends `Filament\Auth\Pages\EditProfile`; adds passkey/WebAuthn and Socialite account-linking sections.
- `app/Filament/Forms/Components/CloudflareTurnstile.php` + `resources/views/filament/forms/components/cloudflare-turnstile.blade.php` — custom Filament field rendering the Turnstile widget (distinct from the validation rule below).
- `resources/views/filament/dashboard/password-requirements.blade.php` — Alpine-driven live password checklist, bound directly to the password field's DOM value (no `->live()`/server round-trip).
- `app/Rules/CloudflareTurnstile.php` — server-side Turnstile `siteverify` validation rule. *(done this session)*
- `app/Filament/Dashboard/Resources/...` — `AlertResource` (My Alerts, wizard-based Create/Edit, with inline create-option modals for Location/Channel selects), `LocationResource`, `ChannelResource` (icon-grid picker step, channel-specific form step, confirmation-code step, expert-mode toggle).
- `app/Filament/Dashboard/Pages/Billing.php` — Spark Livewire wrapper.
- `app/Actions/Support/GenerateChatwootIdentityHash.php`.
- `app/Support/ChannelTypes/` — one `ChannelType` class per supported Apprise service (form schema + URL-builder), plus a registry/contract they implement.
- `database/migrations/..._create_channel_types_table.php` — admin-editable slug/enabled/min_tier/sort-order table backing the picker.
- `app/Actions/Channels/DispatchChannelConfirmationCode.php` and `app/Actions/Channels/VerifyChannelConfirmationCode.php`.
- `app/Support/Geocoding/CensusGeocoder.php` and `app/Support/Geocoding/NominatimGeocoder.php` — thin clients for forward and reverse geocoding respectively.
- `composer.json` — **installed this session**: `stechstudio/filament-impersonate`, `spatie/laravel-activitylog`, `dutchcodingcompany/filament-socialite`, `guava/filament-knowledge-base`, `owenvoke/blade-fontawesome`, `coderflex/filament-turnstile`, `pestphp/pest-plugin-livewire` (dev). **Still open**: a server-side passkey/WebAuthn solution (`laragear/webauthn` is abandoned; its suggested replacement `laravel/passkeys` is client-only, see As-Built Notes), a Filament UI for `spatie/laravel-activitylog`'s `Activity` model (`z3d0x/filament-logger` is abandoned with no replacement — plan is a custom Resource, see As-Built Notes), `fahiem/filament-pinpoint` (evaluate first; `eduardo-ribeiro-leaflet` as fallback), Spark (moved to Phase 2). `filament/blueprint` is already installed.

## Verification

- `php artisan filament:list-panels` shows both `dashboard` (default) and `admin` registered with the correct paths/colors.
- Visiting `/` redirects into `/dashboard`'s login/registration; registering creates a Free-tier `User` with no Spark interaction, requires a valid Turnstile token, rejects passwords that don't meet the policy, and leaves the account unverified/inactive until the email-verification link is clicked.
- Forgot-password and reset-password routes work end-to-end on `/dashboard`.
- Logging in as a seeded staff user reaches `/admin`; a non-staff user is denied.
- Toggle `WXNOTIFY_MODE=selfhost` locally and confirm Billing and the Chatwoot widget disappear from `/dashboard`.
- Feature tests (Pest, using Filament's own testing helpers per `SETUP`) for: registration creates Free tier and enforces the password policy and Turnstile check; unverified accounts can't reach dashboard resources; non-staff blocked from `/admin`; admin "Change Tier" action updates the override and is visible in the audit log; My Alerts wizard produces the correct rule shape per category (at minimum Weather Alerts and Threshold Alerts, to prove the branching works) and its Location/Channel selects can create a related record inline without losing wizard state.
- Manual pass in the browser: dark/light toggle on both panels, tier-gated nav items show the locked/upgrade state as a Free user, the live password-requirements checklist updates correctly as you type, Chatwoot widget loads with a signed identity on `/dashboard` when logged in.
- Channel creation: a Free subscriber can add Email plus exactly one no-cost channel, sees the remaining no-cost tiles and all BYO-paid tiles locked once that slot is filled, and a Plus/Pro subscriber can add BYO-paid channels and use expert mode; a channel isn't marked verified/usable until the confirmation code (from a real Apprise dispatch) is entered correctly; disabling a channel type in the `channel_types` table removes it from the picker without a deploy.
- Location picker: typing an address moves the map marker and vice versa; a "lat, lon" pair skips geocoding; an ambiguous address shows a candidate dropdown rather than guessing; "Use my location" places an approximate pin from the browser's Geolocation API; dragging or tapping the marker updates the address field.

---

## Phase 0 Filament Blueprint (implementation spec)

Written using `filament/blueprint` (now installed) against verified Filament v5 docs (via `search-docs`). This is the specification-ready plan for the Filament-touching Phase 0 work that was on hold — panels, auth pages, Billing. It supersedes the narrative description of these pieces above wherever the two differ (namespaces, exact config chains); the sections above still hold for product/UX intent.

**No Resources are created in this phase** — `AlertResource`, `LocationResource`, and `ChannelResource` are Phase 1. Nothing here defines a Filament Resource.

### Before Running Anything

- **Confirm Spark driver(s) with the user** before requiring packages — this plan assumes both `laravel/spark-stripe` and `laravel/spark-paddle` per the source plan's ARCH section, but confirm before running `composer require`.
- **Guava Knowledge Base's actual Composer package name** wasn't verified via `search-docs` (third-party, not in the indexed corpus) — check https://filamentphp.com/plugins/guava-knowledge-base for the exact `composer require` string before running it.
- **`dutchcodingcompany/filament-socialite`'s exact API** (panel plugin registration syntax, how it surfaces on the profile page) wasn't verified via `search-docs` either — check its own README at implementation time rather than guessing method names.
- **`php artisan make:filament-page` flags for targeting a specific panel** weren't confirmed via docs search — run `php artisan make:filament-page --help` first per the project's own Boost guidelines ("inspect required options before running").

### 1. Commands

Run in this order:

```bash
# New subscriber-facing panel (creates app/Providers/Filament/DashboardPanelProvider.php,
# auto-registers in bootstrap/providers.php)
php artisan make:filament-panel dashboard --no-interaction

# Packages — confirm Spark driver(s) and Guava's package name first (see above)
composer require laravel/spark-stripe laravel/spark-paddle --no-interaction
composer require stechstudio/filament-impersonate --no-interaction
composer require spatie/laravel-activitylog --no-interaction
composer require z3d0x/filament-logger --no-interaction
composer require dutchcodingcompany/filament-socialite --no-interaction
composer require laragear/webauthn --no-interaction
composer require guava/filament-knowledge-base --no-interaction  # verify exact name first

# spatie/laravel-activitylog migration
php artisan vendor:publish --tag=activitylog-migrations --no-interaction

# Custom Turnstile field (verified v5 pattern: Filament\Forms\Components\Field subclass + Blade view)
php artisan make:filament-form-field CloudflareTurnstile --no-interaction

# Auth pages (Register, EditProfile) and the Billing page have no dedicated scaffold
# command in Filament's docs — create these classes directly at the locations below,
# extending the base Filament\Auth\Pages\* classes per Filament's documented
# customization pattern (https://filamentphp.com/docs/5.x/users/overview#customizing-the-authentication-features).
# Billing is a plain standalone page — try:
php artisan make:filament-page Billing --no-interaction   # verify it targets the dashboard panel; --help first

php artisan migrate --no-interaction
```

### 2. Models

`App\Models\User` — already carries `is_staff`, `tier_override`, `tier_override_reason`, `tier()`, and `MustVerifyEmail` from earlier this session. One addition still needed:

```
Model: User (modify, not new)
  Add interface: Filament\Models\Contracts\FilamentUser
  Docs: https://filamentphp.com/docs/5.x/users/overview#authorizing-access-to-the-panel

  Add method:
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->is_staff,
            default => true,
        };
    }
```

This is the actual enforcement mechanism for "`/admin` is staff-only" — without it, Filament allows all users into every panel in non-local environments per its own docs, so this isn't optional.

### 3. Panels

```
Panel: Dashboard (subscriber-facing, new)
  Command: php artisan make:filament-panel dashboard --no-interaction
  Location: App\Providers\Filament\DashboardPanelProvider
  Docs: https://filamentphp.com/docs/5.x/panel-configuration

  Config:
    ->default()
    ->id('dashboard')
    ->path('dashboard')
    ->colors(['primary' => Color::Blue])   # Filament\Support\Colors\Color; swap for Color::hex('#...') if a specific brand blue is supplied later
    ->login()
    ->registration(App\Filament\Dashboard\Pages\Auth\Register::class)
    ->passwordReset()
    ->emailVerification()
    ->profile(App\Filament\Dashboard\Pages\Auth\EditProfile::class)
    ->multiFactorAuthentication([
        Filament\Auth\MultiFactor\App\AppAuthentication::make(),
    ])
    ->renderHook(
        Filament\View\PanelsRenderHook::SIDEBAR_FOOTER,
        fn (): View => view('filament.dashboard.did-you-know'),
    )
    ->renderHook(
        Filament\View\PanelsRenderHook::BODY_END,
        fn (): ?string => auth()->check() && config('wxnotify.mode') === 'cloud'
            ? Blade::render('resources/views/filament/dashboard/chatwoot-widget.blade.php', [
                'identityHash' => app(App\Actions\Support\GenerateChatwootIdentityHash::class)(auth()->user()),
              ])
            : null,
    )

  Note: dark mode is ON by default in Filament v5 (no ->darkMode() call needed) —
  only ->darkMode(false) or ->darkMode(isForced: true) change that default. Do
  neither; leave the default light/dark toggle in place.

  Note: "Did You Know?" content model/admin CRUD is Phase-1+ (lives with the
  Content admin tool); the render hook view can render a static placeholder for
  Phase 0 rather than blocking on that.

Panel: Admin (staff-only, existing — modify)
  Location: App\Providers\Filament\AdminPanelProvider (existing file)
  Docs: https://filamentphp.com/docs/5.x/panel-configuration

  Changes from current file:
    Remove: ->default()
    Change: ->colors(['primary' => Color::Amber]) → ->colors(['primary' => Color::Teal])
    Keep: ->id('admin'), ->path('admin'), ->login(), existing middleware/authMiddleware
    Add: ->registration() is NOT called here — staff accounts are created by other
         staff (via the future Users & Impersonation resource in Phase 1+), not
         self-registration. Do not add ->registration() to this panel.
```

### 4. Custom Pages

```
Page: Register
  Location: App\Filament\Dashboard\Pages\Auth\Register
  Extends: Filament\Auth\Pages\Register
  Docs: https://filamentphp.com/docs/5.x/users/overview#customizing-the-authentication-features

  Register: ->registration(App\Filament\Dashboard\Pages\Auth\Register::class) on DashboardPanelProvider

  Form (override form(Schema $schema): Schema):
    Columns: 1
    Fields, in order:
      - $this->getNameFormComponent()   # base Register page provides this — full name
      - $this->getEmailFormComponent()  # base — validates unique:users,email per Filament's own logic
      - Field: turnstile_token
          Component: App\Filament\Forms\Components\CloudflareTurnstile (custom field class)
          Docs: https://filamentphp.com/docs/5.x/forms/custom-fields
          Command: php artisan make:filament-form-field CloudflareTurnstile --no-interaction
          Validation: required, App\Rules\CloudflareTurnstile (the validation rule — already
            implemented this session; different from the field class above, which just renders
            the widget and captures its response token into form state)
          Config: ->dehydrated(false)
          Note: extends Filament\Forms\Components\Field per the verified v5 custom-field
          pattern (form fields are plain PHP classes, not Livewire components — no public
          properties/methods are reachable from the Blade view). Its view
          (resources/views/filament/forms/components/cloudflare-turnstile.blade.php)
          renders the Turnstile widget via config('services.turnstile.site_key') and wires
          the widget's callback to the field's Alpine-bound state so the response token ends
          up in form state under 'turnstile_token' for App\Rules\CloudflareTurnstile to validate.
      - $this->getPasswordFormComponent()
          ->rule(Illuminate\Validation\Rules\Password::min(8)->letters()->mixedCase()->numbers()->symbols())
          ->extraInputAttributes(['data-password-input' => true])
          ->afterContent(view('filament.dashboard.password-requirements'))
          Note: implemented and verified working. Deliberately NOT ->live() — the checklist
          view (resources/views/filament/dashboard/password-requirements.blade.php) finds the
          input via the data-password-input attribute with a plain document.querySelector and
          attaches a native 'input' listener itself, entirely client-side in its own Alpine
          scope — no wire:model.live, no Livewire round-trip, and no dependency on whether
          afterContent() exposes the same $getStatePath()-style helpers a custom Field's own
          view gets (it doesn't need to). Original spec called this ->hint(view: ...); that
          method doesn't fit a block-level checklist and was corrected during implementation.
      - $this->getPasswordConfirmationFormComponent()

  Imports:
    - Filament\Schemas\Schema
    - Illuminate\Validation\Rules\Password
    - App\Rules\CloudflareTurnstile

  Behavior notes:
    - Free-tier account is the implicit default — no plan/tier field on this form at all;
      tier() already defaults to Free via tier_override being null.
    - Do not add a Spark/billing step here — Billing page (below) is where an upgrade happens, post-registration.

Page: EditProfile
  Location: App\Filament\Dashboard\Pages\Auth\EditProfile
  Extends: Filament\Auth\Pages\EditProfile
  Docs: https://filamentphp.com/docs/5.x/users/overview#customizing-the-authentication-features

  Register: ->profile(App\Filament\Dashboard\Pages\Auth\EditProfile::class) on DashboardPanelProvider

  Form (override form(Schema $schema): Schema):
    Columns: 1
    Fields:
      - $this->getNameFormComponent()
      - $this->getEmailFormComponent()
      - $this->getPasswordFormComponent()
      - $this->getPasswordConfirmationFormComponent()
      - [passkey/WebAuthn management section — exact component depends on laragear/webauthn's
         own Livewire/Blade integration; verify its docs at implementation time rather than
         guessing a component name here]
      - [Socialite account-linking section — same caveat, verify dutchcodingcompany/filament-socialite's
         own integration point]

  Note: TOTP 2FA UI is added automatically by ->multiFactorAuthentication() on the panel —
  do not build custom UI for it.

Page: Billing
  Command: php artisan make:filament-page Billing --no-interaction  (verify --panel targeting via --help first)
  Location: App\Filament\Dashboard\Pages\Billing
  Docs: https://filamentphp.com/docs/5.x/navigation/custom-pages

  Navigation:
    Group: Account
    Icon: Heroicon::CreditCard
    Sort: none specified — place after My Profile in the Account group

  Content:
    - Renders Spark's subscription-management Livewire component inside this page's view
      (exact Spark v5-compatible component name to confirm against Spark's own docs at
      implementation time — Spark's Filament-embeddability specifics weren't in the
      indexed Filament docs corpus)
    - No custom form/table on this page — it's a thin wrapper
```

### 5. Authorization

```
Panel: dashboard
  Authorization: All authenticated users (registration is open; every new account starts Free)

Panel: admin
  Authorization: canAccessPanel() on User returns is_staff (see Models section above) —
  effectively "staff accounts only," where staff accounts are provisioned directly
  (seeder/tinker for Phase 0; a proper admin UI for creating staff accounts is out of
  scope until Phase 1+ builds the Users & Impersonation resource)
```

### 6. Widgets

None for Phase 0. The Dashboard landing page's stat widgets (active alert rules, locations watched, recent alerts) depend on `alert_rules`/`locations` tables that don't exist until Phase 1 — building them now would query nonexistent tables. Leave the `/dashboard` panel's default `Dashboard` page as Filament's stock one (`AccountWidget` + `FilamentInfoWidget`, matching what `/admin` already has) for Phase 0; replace it with the real stat widgets when Phase 1 adds the underlying resources.

### 7. Tests

```
DashboardPanelProvider / AdminPanelProvider:
  - php artisan route:list --path=dashboard / --path=admin shows both panels' routes with correct paths (no `filament:list-panels` command exists in this Filament version — verified)
  - a user with is_staff=false is denied access to /admin (403 or redirect, per Filament's default behavior)
  - a user with is_staff=true can access /admin
  - any authenticated user can access /dashboard

Register (App\Filament\Dashboard\Pages\Auth\Register):
  Validation (use dataset pattern):
    - name: required
    - email: required, email, unique:users,email
    - password: required, min:8, must contain lowercase, uppercase, number, and one of @$!%*?&
    - password_confirmation: required, same:password
    - turnstile_token: required, fails when Cloudflare's siteverify returns success:false (Http::fake)
  Behavior:
    - successful registration creates a User with tier_override null (defaults to Free via tier())
    - successful registration does NOT create any Spark/Stripe/Paddle record
    - after registration, the account is unverified and dashboard resources are inaccessible until the email verification link is followed

EditProfile:
  - authenticated user can update name/email/password through the page
  - TOTP setup flow is reachable and functions (Filament's own multi-factor UI — smoke test only, not re-testing Filament's internals)

Billing:
  - page loads for an authenticated user without error
  - self-hosted mode (WXNOTIFY_MODE=selfhost) hides the Billing nav item entirely — assert canAccessPanel/nav visibility, not just page load

Chatwoot render hook:
  - identity hash rendered into the page for a logged-in user matches GenerateChatwootIdentityHash's output (already unit-tested this session)
  - widget is absent entirely when config('wxnotify.mode') === 'selfhost'
  - widget is absent for a guest (unauthenticated) visitor on /dashboard's public pages
```

### 8. As-Built Notes (post-implementation)

Recorded after actually building and browser-verifying the above — corrections and additions that weren't known when the spec sections were written.

**Google Socialite is fully wired**, not just stubbed. `DashboardPanelProvider` registers `FilamentSocialitePlugin` with a `google` provider (icon via the now-installed `owenvoke/blade-fontawesome`), `->registration()` enabled (SSO can create new accounts, not just log in to existing ones — matches self-registration already being open), and a custom `->createUserUsing()` closure: mass-assigns only `name`/`email`/`password` (a random 40-char string, since `password` is `NOT NULL` and a plaintext-generated value works fine through our `hashed` cast), then calls `$user->markEmailAsVerified()` directly — Google already verified the address, so there's no reason to make a subscriber verify it a second time. This is different from the original spec's assumption that the plugin surfaces its own account-linking UI on the profile page — it doesn't; that's login/register-page-only in this package, so `EditProfile` has no Socialite section. `config/services.php` needs a `google` block (`client_id`/`client_secret`/`redirect`, env-driven, added) and the plugin ran its own migration (`socialite_users` — a proper join table, not a column on `users`). **Apple SSO is not implemented** — Apple isn't a built-in Socialite driver (needs the community `socialiteproviders/apple` package) and Apple Sign In requires a JWT-based client secret plus real Apple Developer Program credentials neither of which exist in this environment; deferred alongside Turnstile/Chatwoot/Spark as "needs real credentials."

**A third panel exists**: `guava/filament-knowledge-base`'s installer auto-created `KbPanelProvider` (`id('kb')`, `path('kb')`, Amber) and registered it in `bootstrap/providers.php` — this wasn't something built manually. `DashboardPanelProvider` points `KnowledgeBaseCompanionPlugin::make()->knowledgeBasePanelId('kb')` at it, which is what actually surfaces the KB link/companion in the `/dashboard` sidebar per the original design's "lower-left corner, floating" placement.

**A real Vite theme now exists for `/dashboard`**: the Guava installer also created `resources/css/filament/dashboard/theme.css` (importing Filament's base theme + Tailwind, with `@source` scanning `app/Filament/Dashboard/**` and `resources/views/filament/dashboard/**`) and wired `->viteTheme(...)` on the panel plus a `vite.config.js` entry. This retroactively resolves the earlier gap where custom Tailwind classes in `password-requirements.blade.php` weren't in Filament's default compiled bundle — they're now inside the scanned content paths and would work as real utility classes if reverted from the inline-style workaround (not done — the inline-style version already works and touching it isn't necessary).

**Debugging note worth keeping**: a stale `public/hot` file (left behind by an earlier, failed `composer run dev` attempt) silently pointed the whole `/dashboard` panel at a Vite *dev server* that wasn't running, which made the page load with zero Filament CSS applied (looked like a totally broken page, not an error). Laravel's Vite helper prefers `public/hot` over the built manifest whenever that file exists, with no warning if the dev server behind it is down. If a panel ever looks unstyled after a `viteTheme()` change, check for `public/hot` before assuming the theme config is wrong.

**`muazzambuilds/filament-turnstile` was evaluated and rejected** (not installed): its only release (v1.0.0) requires `illuminate/contracts ^11.0|^12.0`, incompatible with this project's Laravel 13 pin — Composer refuses the install outright. Worth re-checking its Packagist page periodically in case it adds Laravel 13 support later.

**Superseded by `coderflex/filament-turnstile` (v4.0.1), which *is* Laravel 13-compatible** (`illuminate/contracts ^11.28|^12.0|^13.0`) and is now installed and wired in, replacing the hand-built implementation entirely. `Register.php`'s Turnstile field is now just `Coderflex\FilamentTurnstile\Forms\Components\Turnstile::make('turnstile_token')` — the package's own `setUp()` already applies `->required()`, its own `TurnstileCheck` validation rule, and `->dehydrated(false)`, so no chaining needed. Its Blade view self-loads the Cloudflare script via Filament's native `x-load-js` Alpine directive (cleaner than the hand-built version's manual `document.createElement('script')`) and wires the token through `$wire.entangle()`/`$wire.set()` the same way. The env vars are package-defined, **not** the names used earlier: `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY` (not `CLOUDFLARE_TURNSTILE_*`), read from the package's own `config('turnstile.*')`, not `config('services.turnstile.*')` — the `services.php` block from earlier this session was removed. **Deleted as dead code**: `app/Filament/Forms/Components/CloudflareTurnstile.php`, its Blade view, `app/Rules/CloudflareTurnstile.php`, and `tests/Feature/CloudflareTurnstileRuleTest.php` (tested the now-deleted rule). `RegisterTest.php` needed no changes beyond that — the package posts to the same `challenges.cloudflare.com/turnstile/v0/siteverify` host, so the existing `Http::fake()` mocks kept working as-is. One real gap the swap surfaced: the package throws `SecretKeyNotFoundException` (not a graceful validation failure) when `TURNSTILE_SECRET_KEY` is unset, which broke tests until `TURNSTILE_SITE_KEY`/`TURNSTILE_SECRET_KEY` test values were added to `phpunit.xml`'s `<php>` env block alongside the other test-environment overrides.

**Local environment moved to Laravel Sail (Docker) mid-session** — `.env` now points `DB_CONNECTION=pgsql`/`DB_HOST=pgsql`, `REDIS_HOST=redis`, `MAIL_HOST=mailpit` (Docker-internal service hostnames), matching the source plan's own `SETUP` recommendation to run real Postgres locally rather than sqlite-and-swap-later. These hostnames only resolve inside the Sail Docker network, not from a bare host-level shell — running `vendor/bin/pest`/`php artisan` directly on the host now fails with a DNS error (`could not translate host name "pgsql"`) even though nothing is actually broken. Run commands inside the app container instead, e.g. `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest"`. (Correction to an earlier note in this section: the compose file exists — it's named `compose.yaml`, not `docker-compose.yml`, which is why an early search for it came up empty.)

**Bug found and fixed: verification emails never reached Mailpit.** Root-caused with hard evidence (per `systematic-debugging`), not guessed: `Filament\Auth\Notifications\VerifyEmail` implements `ShouldQueue`, `QUEUE_CONNECTION=database` in this Sail setup, and `compose.yaml` had no queue-worker service at all — confirmed by finding 4 unprocessed rows sitting in the `jobs` table with 0 attempts, and no queue-related process anywhere in `docker ps` or the app container's `supervisord`. **Fix**: added a `queue` service to `compose.yaml` (same `sail-8.5/app` image, `command: php artisan queue:listen --tries=1` — Sail's `start-container` entrypoint execs whatever command is given instead of launching supervisord when one is provided, which is the documented way to add a worker). Bringing it up surfaced a second, previously-latent issue: `WWWUSER`/`WWWGROUP` were never set in `.env` (this project's containers were started via plain `docker compose up`, not the `vendor/bin/sail` wrapper that normally injects them), so the new service's `gosu $WWWUSER` step failed with `unable to find user "php"` — the empty `$WWWUSER` disappeared in word-splitting, shifting `$@` so `gosu` read the command's first token as the target user. Fixed by adding real `WWWUSER=502`/`WWWGROUP=20` (this host's actual uid/gid) to `.env` — intentionally not added to `.env.example`, since Sail's own convention is to leave these for the `sail` wrapper or the local `.env` to supply, never to hardcode a host-specific value into a template other developers share. After both fixes: the 4 stuck jobs drained immediately and the corresponding emails appeared in Mailpit; a fresh live registration through the browser confirmed the email now arrives within ~2 seconds going forward. Added `tests/Feature/RegisterTest.php`'s `'a successful registration actually dispatches the verification email'` test (`Notification::fake()` + `assertSentTo`) as a permanent regression guard — the existing tests only checked that the account ended up unverified, never that the notification was actually dispatched, which is exactly the gap that let this ship unnoticed.

**Custom weather-photo backgrounds added to `/dashboard` auth pages** via `swisnl/filament-backgrounds` (`FilamentBackgroundsPlugin`), added to `DashboardPanelProvider`'s plugin list with a custom `MyImages` provider pointing at `public/images/backgrounds/weather_wallpapers/` (7 real weather photos — storms, lightning, calm landscapes) rather than the package's bundled stock "curated-by-swis"/"triangles" sets (deleted). Picks a new random image on every page load (no `remember()` TTL set) — browser-confirmed on both `/dashboard/login` and `/dashboard/register`, each showing a different photo. `/admin` intentionally has no background — matches this session's established branding split (subscriber panel = inviting/branded, staff panel = plain utility tool). Repo is now under git (`git init` + initial commit happened this session, user-run) — this and all changes going forward get committed with descriptive messages per the user's standing instruction.
