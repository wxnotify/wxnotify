# Subscriber Unit Preference (Imperial / Metric) — Design

Source: owner request — let a subscriber choose Imperial or Metric units; it should affect the Daily Summary emails, threshold alerts, and anywhere in the UI that displays these units; default Imperial; the Profile page is the preferred home but the owner was open to suggestions.

## Goal

Add a per-subscriber `unit_system` (Imperial default, Metric optional), set on the Profile page, that every subscriber-facing display of a measurement follows. **Imperial is the canonical storage and computation unit everywhere**; Metric is a conversion applied at the edges (display, and form input converted back). Imperial output must be unchanged from today.

## Decisions (user-approved, from brainstorming)

- **Where the setting lives: the Profile page** (`App\Filament\Dashboard\Pages\Auth\EditProfile`), as a "Units" toggle next to name/email/password. It is an account-level preference that affects emails and alerts, not one screen; per-alert units were rejected (it would make the setting per-alert rather than per-person).
- **Storage stays imperial** (`alert_rules.threshold_value` in °F/mph/%, `alert_rules.radius_miles` in miles; every internal computation, including `EvaluateThresholdAlert` and `MatchLightningFlashes`, remains imperial). Switching units therefore never changes what an existing alert means: "Temperature below 32°F" simply displays as "below 0°C". Rejected: a per-rule unit snapshot (mixed units in the UI after a profile change) and migrating storage to metric (churn with no benefit for a US-centric service).
- **One setting, two systems.** No mixing per measurement (e.g. °C with miles). No admin or tier gating; every subscriber can choose.
- **Metric units**: temperature °C; wind speed km/h (not m/s); pressure hPa; distance and visibility km; elevation m; precipitation depth cm. Imperial: °F, mph, inHg, mi, ft, in.
- **NWS prose is converted, conservatively.** NWS's detailed forecast text (added to the 7-day rows) and hourly wind text are written in imperial units. For Metric subscribers a small converter rewrites NWS's standardized phrases; any sentence it cannot fully convert is left exactly as NWS published it — never half-converted or guessed.
- **Non-convertible text stays as issued**: the raw NWS product text inside alerts (e.g. "winds 60 mph", "hail 1 inch") is not converted.
- **Defaults and scope**: existing and new users are Imperial (column default); the setting takes effect at the next message/render (stored text — delivery-log subjects, already-sent notifications — is not rewritten).

## Verified against live NWS text (2026-10-02, 112 periods from 8 regions)

The unit-bearing phrases that occur in `detailedForecast`:

- `with a high near N` / `High near N`, `with a low around N` / `Low around N` (temperature, **no unit symbol**).
- `temperatures falling to around N in the afternoon` (and `rising to`), `Heat index values as high as N` (temperature, no unit).
- `East wind N to N mph`, `Northwest wind around N mph`, `with gusts as high as N mph` (speed, unit `mph`).
- Number-bearing text that must **not** change: `Chance of precipitation is N%`, and clock times (`before 5pm`, `after 11am`, `between 2pm and 4pm`, `by 5pm`).
- Winter/other templates not in this sample but standard NWS wording, covered by the converter and its tests: `New snow accumulation of N to N inches`, `around N inch`, `Wind chill values as low as -N`, `rising to around N`, `steady temperature around N`.
- Prose that cannot be reliably converted and is therefore left as published by the sentence rule below: fraction words (`a tenth of an inch`, `half an inch`).

## Data

- `users.unit_system` — string, not null, default `imperial`; migration adds the column; no data backfill needed (the default applies).
- `App\Enums\UnitSystem` (`Imperial = 'imperial'`, `Metric = 'metric'`), `label()` ("Imperial (°F, mph, mi)" / "Metric (°C, km/h, km)"); `User` casts `unit_system` to it and adds it to `#[Fillable]` (the Profile form saves through `update()`, which mass-assignment-guards). `UserFactory` needs no change (DB default).
- No settings/config: the system is per user. `TierThresholdSettings::lightning_min_radius_miles` stays in miles (admin-facing); the subscriber form displays it converted.

## The conversion service

`App\Support\Units` (one class, one place that knows factors and labels; constructed from a `UnitSystem`):

- `Units::for(User $user)`, `Units::forRule(AlertRule $rule)` (the rule owner's system).
- Display (imperial input → string in the system): `temperature(float $f, int $decimals = 0)` → `"54°F"`/`"12°C"`; `speed(float $mph)` → `"10 mph"`/`"16 km/h"`; `pressure(float $inHg)` → `"30.27 inHg"`/`"1025 hPa"`; `distance(float $mi, int $decimals = 1)` → `"10 mi"`/`"16 km"` (whole numbers print without a trailing `.0`); `elevation(float $ft)` → `"5,085 ft"`/`"1,550 m"`; `depth(float $inches)` → `"2 in"`/`"5 cm"`.
- Numbers only (for form fields): `temperatureValue(float $f): float`, `speedValue`, `distanceValue`, and labels `temperatureUnit()`, `speedUnit()`, `distanceUnit()`.
- Input (display unit → canonical imperial): `temperatureToFahrenheit(float $value): float`, `speedToMph`, `kilometersToMiles`.
- Factors: °C = (°F − 32) × 5/9; km/h = mph × 1.609344; hPa = inHg × 33.8639; km = mi × 1.609344; m = ft × 0.3048; cm = in × 2.54. Imperial methods are identity (formatting only), so imperial output is unchanged byte-for-byte.
- `ThresholdVariable::unit()` gains an optional `UnitSystem` parameter (default Imperial, so existing callers are unchanged); Metric returns `°C` / `km/h` / `%`.

## Surfaces that follow the setting

| Surface | Change |
| --- | --- |
| Daily Summary email — `DailySummaryPresenter` | Current temp, feels like, dewpoint (°); wind speed and gust; barometer; visibility; station elevation; hourly temps; 7-day High/Low temps — all through `Units`. The presenter reads the system from the report. |
| `BuildDailySummaryReport` | Adds `'units' => 'imperial'\|'metric'` to the report (plain string, queue-safe). `DispatchScheduledAlerts::reportFor` passes the rule owner's system in. Reports queued before deploy lack the key → Imperial. |
| NWS text in the email | Hourly `wind` string and each 7-day `summary` are passed through `NwsTextUnits` (below) for Metric. |
| Brief text — `BuildDailySummaryMessage` | `"Today: Sunny, high 88°F."` → `"… high 31°C."` for Metric; takes the system (default Imperial). |
| Threshold alert messages — `DispatchScheduledAlerts::evaluateThreshold` | Threshold and peak value converted and labelled in the system. The comparison itself stays imperial. |
| Motion line — `FormatMotionVector` (called from `ComposeTemplatedAlertBody`) | `"Moving ESE at 20 mph"` → km/h; `ComposeTemplatedAlertBody` already holds the rule, it passes the system down. |
| Rule names — `AlertRule::displayName()` | Threshold (`"Temperature below 0°C"`) and Lightning (`"Lightning within 24 km of Home"`) use the **rule owner's** system. Admin panel lists and delivery-log views that call it therefore show the owner's units. |
| Alert form — `AlertRuleForm` | Threshold value suffix from the system; value shown converted, saved back converted (see below). Lightning radius label `"Alert radius (km)"`, converted min/max validation, converted helper text. |
| Profile page — `EditProfile` | New "Units" `ToggleButtons`/`Radio` field bound to `unit_system`. |

Stored text is not rewritten: `delivery_logs.subject`, database notifications, and `RecentAlertsWidget` rows built from stored subjects keep what was sent. Where the widget falls back to `displayName()` it uses the owner's current system.

## Form conversion and the no-drift guard

- **Threshold value**: the field displays `temperatureValue()`/`speedValue()` of the stored imperial value, to one decimal, trimming `.0` — except Metric **wind** thresholds (wind speed, gusts), which are **whole km/h** (the stored 0.1 mph is coarser than 0.1 km/h, so a fractional km/h would not come back as the same number; whole km/h always does) — in the form, the rule name and the threshold message (`ThresholdVariable::displayDecimals()`); on save it converts back with `…ToFahrenheit()/speedToMph()` and the column's existing `decimal:1` cast applies. Percent variables (humidity, precip chance) are unit-free and untouched.
- **Radius**: Metric input is whole km; stored as whole miles (`round(km / 1.609344)`), min/max shown in km (`ceil` of the converted minimum, `floor` of the converted 50-mile maximum) so any displayed-valid value converts to a valid mile count. The admin setting `lightning_min_radius_miles` stays in miles.
- **No-drift guard**: a stored 35°F displays as 1.7°C; saving without touching the field must not rewrite it to 35.06°F → 35.1°F. The save hook compares the submitted display value to the display value of the stored canonical value and, when they are equal, keeps the stored value unchanged. Same for radius. Changing the *profile* unit never rewrites any stored rule.

## `NwsTextUnits` (Metric prose conversion)

`App\Support\NwsTextUnits::toMetric(string $text): string`. Works **sentence by sentence** (split on `. ` boundaries that follow a sentence, keeping `1.5`-style decimals intact):

1. Convert recognized phrases, case-insensitively, in this order:
   - speeds: `(\d+) to (\d+) mph`, `around (\d+) mph`, `as high as (\d+) mph`, `(\d+) mph` → km/h (whole numbers);
   - depths: `(\d+(?:\.\d+)?) to (\d+(?:\.\d+)?) inch(es)?`, `(\d+(?:\.\d+)?) inch(es)?`, `around N inch(es)?` → cm (one decimal where needed, whole otherwise);
   - temperatures — only after a known temperature lead-in, never a bare number: `(high|low) (near|around) (-?\d+)`, `(falling|rising) to (near|around) (-?\d+)`, `(steady )?temperatures? (near|around) (-?\d+)`, `(heat index|wind chill) values as (high|low) as (-?\d+)` → °C (whole numbers; the `°`/unit symbol is added: NWS's own text has none, the converted text names the unit once per phrase, e.g. `high near 26°C`).
2. **Leave alone** (never matched): numbers followed by `%`, `percent`, `am`, `pm`, or preceded by `before|after|between|by|until|and` as a clock time.
3. **Sentence safety rule**: after conversion, if the sentence still contains a unit token (`\b(mph|inch(es)?|degrees?)\b` or a `°F`) the sentence reverts to the original NWS wording. A sentence is never partly converted.
4. Idempotent and total: text with no recognized pattern is returned unchanged; `toMetric(toMetric($t)) === toMetric($t)`.

Imperial subscribers never call it.

## Non-goals

- Mixing unit systems per measurement; 12/24-hour time; locale-specific number formatting.
- Converting the raw NWS product text inside alerts, or rewriting any stored historical text.
- The admin panel's own settings and displays (admin-facing values such as `lightning_min_radius_miles` stay imperial), and the Test Product Generator.
- Wind in m/s; pressure in kPa/mb alternatives.
- A registration-time unit prompt (the default applies; change it in Profile).

## Edge cases

- **Switching units with existing alerts**: nothing stored changes; every display re-renders in the new system.
- **Switching between a threshold's rendering and an edit**: the no-drift guard keeps untouched fields exactly as stored.
- **Reports queued before deploy** have no `units` key → rendered Imperial (same as before).
- **Negative temperatures** (`-5`) convert correctly in both directions; `-0` is printed as `0`.
- **NWS text with fraction words** (`a tenth of an inch`) fails the sentence safety rule → that sentence stays imperial; the rest of the forecast still converts.
- **A rule owner changes unit system between evaluation and delivery**: the message is formatted at dispatch time with the system current then.

## Testing

- `Units`: every conversion and its inverse (round trips within tolerance), labels, identity for Imperial, `-0` handling, whole-number formatting.
- `ThresholdVariable::unit()` in both systems.
- `DailySummaryPresenter` and `DailySummaryMail`: a Metric report renders °C, km/h, hPa, km, m everywhere (current, hourly, periods, header elevation) and an Imperial report is byte-for-byte what it is today; a report without `units` renders Imperial.
- `BuildDailySummaryReport`: carries `units`; `DispatchScheduledAlerts` passes the owner's system.
- `BuildDailySummaryMessage`, `evaluateThreshold` message, `FormatMotionVector`, `AlertRule::displayName()`: Metric and Imperial.
- `AlertRuleForm`: Metric create/edit round trip for a threshold and a lightning radius (stored canonical value correct); the no-drift guard (save untouched → stored unchanged, including 35°F ↔ 1.7°C and 15 mi ↔ 24 km); converted min/max validation.
- `EditProfile`: the field exists, defaults to Imperial, saves Metric, and persists (the `#[Fillable]` entry works).
- `NwsTextUnits`: a corpus test over the live-sampled phrase set (committed as a fixture) plus hand-written winter phrases — every `mph`/`inch` phrase converted, times and percents untouched, the sentence safety rule (fraction words stay imperial), idempotence, empty/odd input.
- Regression: the existing Imperial tests across these surfaces keep passing unchanged.

Run the affected tests, Pint, then the full suite inside the app container (`docker exec wxnotify-nextgen-front-end-1 sh -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --compact'`).

## Open items for the plan (not design questions)

- How the form's convert-in/convert-out hooks are expressed in Filament (`formatStateUsing`/`dehydrateStateUsing` vs. `mutateFormDataBeforeFill/BeforeSave` on the Create/Edit pages) and where the no-drift comparison needs the stored canonical value — the plan should pick the smaller change, keeping `locationsSelect()`-style dynamic-step cautions in mind (`.ai/rules/filament.md`).
- Whether `displayName()` takes an optional `UnitSystem` parameter or resolves the owner's system itself (the spec's behaviour — owner's system by default — is fixed).
- Where the live-sampled corpus fixture lives (`tests/Fixtures/`).
