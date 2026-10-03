# Subscriber Default Timezone — Design

Source: owner request — subscribers should be able to set their default timezone; the Profile page feels like the best home but the owner was open to suggestions.

## Goal

Add an optional per-subscriber `timezone`, set on the Profile page, used wherever a Location's own timezone does not apply (or, for the dashboard charts, as the preferred day boundary). A Location's own timezone **always wins** for everything tied to a Location. Subscribers who never set one see **no change in behavior anywhere**.

## Decisions (user-approved, from brainstorming)

- **Where the setting lives: the Profile page** (`App\Filament\Dashboard\Pages\Auth\EditProfile`), a "Timezone" field directly under "Units". It is an account-level preference like Units; place-specific times stay on Locations.
- **Scope** (the owner chose these three; the fourth offered, rendering timestamps in tables/emails/tickets in the subscriber's timezone, is deliberately **not** included):
  1. Pre-fill the timezone on new Locations.
  2. Fallback when a Location has no timezone (mobile/Traccar locations).
  3. Dashboard chart day boundaries.
- **Optional, no backfill.** `users.timezone` is nullable; existing subscribers stay null until they pick one. Unset resolves through the same chain the app uses today (see the table below). Rejected: backfilling from each user's newest Location (hidden magic), and required-with-UTC-default (would shift existing subscribers' charts and fallbacks).
- **No admin/tier gating**; every subscriber can set it. No per-rule timezone override.

## Resolution rules

A stored value counts only if it is a valid IANA identifier (`DateTimeZone::listIdentifiers()`); anything else is treated as unset. "Profile timezone" below means that validated value.

| Where | Resolution | When profile timezone is unset |
| --- | --- | --- |
| New Location form, timezone field | Defaults to the profile timezone (still editable; still required on a stationary location) | Browser detection, exactly as today |
| `DispatchScheduledAlerts` (Daily Summary / Threshold delivery time) and the `AlertRule` "rescheduled-run-stamp reset" check | Location timezone, else profile timezone, else `config('app.timezone')` | Location timezone, else `config('app.timezone')`, as today |
| Quiet hours (`MatchWeatherAlertRules::isWithinQuietHours`) | The matched Location's timezone, else the profile timezone | Not suppressed, as today |
| Dashboard charts (`GetAlertDeliveryStats::timezoneFor`) | Profile timezone, else newest Location **that has a timezone**, else UTC | Newest Location that has a timezone, else UTC |

Notes:

- **Quiet hours are only extended for a Location that matched but has no timezone** (mobile). Rules that match no specific Location (Nationwide, WFO, County/Zone, or Location/Polygon/RiskArea rules with no matching location) still **always deliver**, exactly as today, whether or not a profile timezone is set.
- **Chart quirk fixed**: today "newest Location" ignores whether it has a timezone, so adding a mobile location (timezone null) flips an unset subscriber's charts to UTC. Restricting to Locations with a timezone fixes that for subscribers who never set one.
- Location-timezone values themselves are not newly validated (they only ever come from the timezone select or the coordinate resolver); only the new profile value goes through the validity check.

## Data

- `users.timezone` — nullable string; one migration adds the column (`->nullable()->after('unit_system')`); no backfill; `down()` drops it.
- `User` adds `'timezone'` to `#[Fillable]` (the Profile form saves through `update()`). No cast. `UserFactory` needs no change.

## The resolver

`App\Support\Timezones` (final class, static helpers, mirroring `Units`; it is the one place that decides what a stored timezone means):

- `Timezones::isValid(?string $timezone): bool` — non-blank and in `DateTimeZone::listIdentifiers()`.
- `Timezones::forUser(?User $user): ?string` — the user's profile timezone if valid, else null.
- `Timezones::forLocation(Location $location): ?string` — the Location's timezone if present, else `forUser($location->user)`, else null. Used by quiet hours (null → not suppressed).
- `Timezones::forRule(AlertRule $rule): string` — `$rule->location?->timezone`, else `forUser($rule->user)`, else `config('app.timezone')`. Used by `DispatchScheduledAlerts` and the `AlertRule` reschedule check, replacing their `$rule->location->timezone ?? config('app.timezone')` expressions.

`GetAlertDeliveryStats::timezoneFor` uses `Timezones::forUser($user)` first, then its existing "newest Location" query restricted with `whereNotNull('timezone')`, then UTC (its existing valid-IANA check stays).

## UI

- **Profile page**: `TimezoneSelect::make('timezone')` (Tapp `filament-timezone-field`, already used on the Location form) after the Units toggle: `->label('Timezone')->searchable()->getTimezoneFromBrowser()`, **not required**, helper text along the lines of "Used when a location has no timezone of its own (such as a mobile location), for the day boundaries on your dashboard charts, and as the starting value for new locations. A location's own timezone always takes priority." The field is pre-filled client-side from the browser only when empty (the package's behavior); the value is stored only when the subscriber saves the Profile form (saving the form for any reason stores the pre-filled value — accepted). The subscriber can clear it to go back to "unset".
- **Location form**: the existing `TimezoneSelect` adds `->default(fn (): ?string => Timezones::forUser(auth()->user()))`. With a default present the package does not override it from the browser; with none it detects from the browser exactly as today. The field stays required on stationary locations.
- **Mobile-location notice** (`LocationForm`, the placeholder that says mobile locations "don't have a fixed timezone, so quiet hours … won't take effect"): reworded to say quiet hours for this location use the profile timezone, and won't take effect until one is set on the Profile page. No logic change there.

## Non-goals

- Showing timestamps elsewhere (tables, emails, tickets, billing) in the subscriber's timezone.
- A per-alert-rule timezone, or timezone for rules with no Location.
- Changing quiet-hours semantics for rules that match no specific Location.
- Backfilling existing subscribers; admin-side editing of a subscriber's timezone.
- Re-validating or migrating existing Location timezones.

## Testing

Pest feature tests (Livewire for the pages), container run as for other features:

- `Timezones`: `isValid` (valid, blank, null, garbage, the legacy alias `US/Mountain` is invalid, since `DateTimeZone::listIdentifiers()` excludes backward-compatibility aliases — the same list the timezone select offers, so a subscriber can never store one), `forUser` (set / unset / invalid stored value), `forLocation` (location wins; falls back to user; both missing → null), `forRule` (location wins; user fallback; app timezone).
- Profile page: saves a valid timezone; rejects a bogus value; saves an empty value (clears to null); existing name/email/units saving is unaffected.
- Location form: new location's timezone starts as the profile timezone when set; not forced when unset (still blank server-side, so the browser-detect path applies); a Location's explicit choice is saved unchanged.
- Scheduled dispatch: a rule whose Location has no timezone fires at the delivery time in the profile timezone; with no profile timezone it behaves as today (app timezone); a Location with its own timezone ignores the profile value. The reschedule reset in `AlertRule` follows the same resolution (extend `AlertRuleDeliveryTimeResetTest`).
- Quiet hours (extend `MatchWeatherAlertRulesTest`): a matched mobile Location with no timezone is suppressed inside quiet hours when the owner has a profile timezone, and delivered (not suppressed) when they don't; a matched Location **with** a timezone ignores the profile value; a rule matching no Location still always delivers.
- Charts (extend `GetAlertDeliveryStatsTest`): profile timezone beats the newest Location; unset falls back to the newest Location **with** a timezone (a newer mobile Location with a null timezone no longer flips to UTC); no timezone anywhere → UTC; an invalid stored profile value is ignored.
- Browser check (controller, after execution): the Profile field pre-fills and saves; the Location form's timezone starts as the profile value; a chart's day boundaries move when the profile timezone changes.

## Rules to record

Add a "Timezones" section to `.ai/rules/support.md` (the resolver is the one place that decides what a stored timezone means; a Location's own timezone always wins; unset must keep today's behavior, in particular quiet hours are never suppressed without a resolved timezone), and note the Profile/Location form wiring in `.ai/rules/filament.md` if the implementation hits a trap worth keeping.
