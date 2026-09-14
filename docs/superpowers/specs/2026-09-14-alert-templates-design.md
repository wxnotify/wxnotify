# Alert Templates (v1: subject line + message length) — Design

## Context

The master plan's "Custom alert templates" (FEAT) describes a Plus/Pro feature letting a subscriber build their own alert message by choosing which sections of a product to include (hazard, source, impact, affected locations, expiration, motion vector for a warning; period, temperature, precipitation chance for a forecast), plus an optional subject line. It explicitly assumes this is cheap to build because it draws from *"the same common-structure parser output DATA already produces, formalized as a named schema per entry in the product catalog."*

That parser doesn't exist. Checking the actual delivery path (`app/Jobs/DeliverWeatherAlert.php`, `app/Models/IngestedProduct.php`) confirms `IngestedProduct` only stores what `App\Actions\Pipeline\ExtractProductMetadata` already extracts for matching purposes — `product_id` (VTEC-resolved), `ugc_codes`, `polygon`, and SPC risk levels. There is no hazard/source/impact/expiration/motion-vector extraction anywhere; `DeliverWeatherAlert` sends the raw bulletin text (`Str::limit($product->text, 1500, ...)`) as the body today. Building the full section-picker template feature as spec'd would require a genuinely new narrative-text parser first — a separate, higher-risk undertaking closer in size to the Lightning Alerts pipeline than to a form addition, and out of scope for this pass.

**This is v1, scoped down deliberately**, using only data already available at delivery time: a per-rule custom subject line (with a small, safe set of placeholders) and a choice between today's full message length and a shorter, mechanically-truncated one. Real subscriber value — the plan's own framing calls this "an idea from an actual WxNotify subscriber" — without the parser dependency. The full section-picker version, if built later, is a separate future design.

## Data model

Two new nullable columns on `alert_rules`:

| Column | Type | Notes |
|---|---|---|
| `custom_subject_template` | string, nullable | Raw placeholder string, e.g. `"Tornado Warning for {location}"`. `null`/blank means "use the default" (today's `AlertRule::displayName()`). |
| `message_body_style` | string, nullable, new `MessageBodyStyle` enum (`Full`, `Brief`) | Mirrors this codebase's existing convention of using an enum for a binary choice (`LocationType`, `MobileSource`, `Tier`) rather than a boolean. `null` is treated as `Full` in code — no DB default needed. |

`AlertRule::displayName()` (the existing admin/list-table label) is **not** touched — it stays exactly as it is today, used for UI display and as the fallback when no custom subject is set. The new fields are an orthogonal, optional override on top of it.

## `RenderAlertSubject` (new action)

```php
namespace App\Actions\Weather;

use App\Models\AlertRule;
use App\Models\Product;

class RenderAlertSubject
{
    public function __invoke(AlertRule $rule, ?Product $product = null): string
    {
        if (blank($rule->custom_subject_template)) {
            return $rule->displayName();
        }

        return strtr($rule->custom_subject_template, [
            '{product}' => $product?->name ?? $rule->category->label(),
            '{location}' => $rule->location?->label ?? ($rule->wfo !== null ? "WFO {$rule->wfo}" : 'your area'),
            '{category}' => $rule->category->label(),
        ]);
    }
}
```

Plain `strtr()` — logic-less substitution, nothing executable, matching the master plan's own stated constraint for subscriber-authored template text ("never anything that can execute code, since a subscriber-authored template runs on every dispatch of their alerts"). An unrecognized placeholder (e.g. a typo like `{locaton}`) simply renders literally in the output — harmless, no validation needed, since this is plain string substitution with no security surface.

`{product}` only resolves to a real product name for `WeatherAlerts`/`SpcWpc` rules where the caller has a matched `Product` in hand (via `DeliverWeatherAlert`'s `IngestedProduct->product`); every other category (and any `WeatherAlerts`/`SpcWpc` delivery where the product somehow can't be resolved) falls back to the category label — the same fallback `displayName()` already uses today for the "no products" case.

## Wiring into delivery

Both delivery jobs already load the `AlertRule` before calling Apprise; the override happens right there. No changes to either job's callers (`MatchWeatherAlertRules`, `DispatchScheduledAlerts`, `MatchLightningFlashes` keep composing their "full" title/body exactly as today).

**`app/Jobs/DeliverWeatherAlert.php`** — replaces:
```php
body: Str::limit($product->text, 1500, preserveWords: true),
title: $rule->displayName(),
```
with:
```php
body: Str::limit($product->text, $rule->message_body_style === MessageBodyStyle::Brief ? 280 : 1500, preserveWords: true),
title: app(RenderAlertSubject::class)($rule, $product->product),
```

**`app/Jobs/DeliverScheduledAlert.php`** — already receives a pre-built `$this->title`/`$this->body` from its caller and already loads `$rule` for the `is_active` check. Right before calling `$client->notify()`:
```php
$title = app(RenderAlertSubject::class)($rule);
$body = $rule->message_body_style === MessageBodyStyle::Brief
    ? Str::limit($this->body, 280, preserveWords: true)
    : $this->body;
```
No `$product` argument here — Threshold/DailySummary/Lightning have no `IngestedProduct` concept, so `{product}` always falls back to the category label on this path. Since `DispatchScheduledAlerts`/`MatchLightningFlashes` already compose short, purpose-built messages (typically well under 280 characters), Brief mode is mostly a no-op here — expected, not a bug; it exists for consistency and for the rare longer composed message.

## Wizard UI

Both fields join the existing "Finishing touches" step in `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php` (where `name`/quiet hours already live) rather than a new wizard step — they're the same kind of "optional extras for this specific rule":

```php
Select::make('message_body_style')
    ->label('Message length')
    ->options([MessageBodyStyle::Full->value => 'Full', MessageBodyStyle::Brief->value => 'Brief'])
    ->default(MessageBodyStyle::Full)
    ->visible(fn (): bool => auth()->user()->tier() !== Tier::Free),
TextInput::make('custom_subject_template')
    ->label('Custom subject line (optional)')
    ->maxLength(255)
    ->helperText('Leave blank to use the default. Placeholders: {product}, {location}, {category}.')
    ->visible(fn (): bool => auth()->user()->tier() !== Tier::Free),
```

Hidden entirely for Free subscribers, not shown-and-locked — matching the precedent `TraccarServerResource` already established this session (no "visible but locked with an upgrade explainer" mechanism exists anywhere in this codebase yet to build on, and inventing one here is out of scope for this feature).

## Testing

- **`RenderAlertSubjectTest`**: no custom template falls back to `displayName()`; a template using all three placeholders resolves correctly for a `WeatherAlerts` rule with a matched product; `{product}` falls back to the category label for a category with no product concept (e.g. `Threshold`); `{location}` falls back to `"WFO {code}"` for a `Wfo`-mode rule and to `"your area"` for a `Nationwide`-mode rule with neither location nor WFO; an unrecognized placeholder renders literally rather than erroring.
- **`DeliverWeatherAlertTest`** / **`DeliverScheduledAlertTest`** additions: a rule with a custom subject template sends that rendered title to Apprise (not `displayName()`); `message_body_style = Brief` truncates the body to 280 characters; `Full` (or `null`) preserves today's behavior unchanged.
- **`AlertRuleResourceTest`** additions: a Free subscriber's wizard never shows the two new fields; a Plus/Pro subscriber can set and persist both; editing an existing rule round-trips the stored values correctly.

## Out of scope

- The full section-picker template builder (hazard/source/impact/affected-locations/expiration/motion-vector toggles) and the common-structure parser it depends on — a separate, future design once that parser exists.
- Any "raw" free-text templating mode — the master plan explicitly frames this as a later consideration requiring its own sanitization design, not something this pass touches.
- A shared "visible but locked, opens an upgrade explainer" nav/field treatment for gated features generally — these two fields hide outright for Free instead, matching the one precedent that already exists (`TraccarServerResource`).
