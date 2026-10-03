# Alerts Table: Locations and Channels Columns — Design

Source: owner request — on the Alerts (My Alerts) list page table, Locations and Channels columns should be added.

## Goal

Show, per alert in the subscriber's "My Alerts" table, **where it applies** (Locations) and **where it is delivered** (Channels), so a subscriber can audit their alerts without opening each one. No change to the form, filters or actions.

## Decisions (user-approved, from brainstorming)

- **Placement**: two new columns between **Category** and **Active**, both `toggleable()` and visible by default (Created stays toggleable and hidden by default).
- **Locations column** — what an alert applies to depends on its category and matching mode:
  - **Daily Summary, Threshold and Lightning** rules use their single `location` (`location_id`) → one badge with that location's label.
  - **Weather Alerts and SPC & WPC** rules whose `matching_mode` is `Location`, `Polygon` or `RiskArea` use their saved-locations list (`locations`, the pivot) → one badge per location label, in the relationship's existing order (`locations.id`).
  - **Weather Alerts and SPC & WPC** rules whose mode is `Nationwide`, `Wfo` or `CountyZone` are not tied to a saved location → a single **muted gray** label naming the mode instead of an empty cell: **Nationwide**, **Issuing office**, **County/zone** (short labels from a new `MatchingMode::shortLabel()`, because `label()` is long: "All products, any location").
  - A rule that should have locations but has none (e.g. a location-mode rule whose locations were all deleted, or a scheduled rule whose location is gone — `location_id` is nulled on delete) shows a muted gray **None**.
  - Rejected: a plain dash (ambiguous: "no locations" vs "not location-based") and a generic "Any location" (loses the WFO/county distinction).
- **Channels column** — one badge per attached notification channel (`notificationChannels`, the pivot) using the channel's `label`; an alert with no channels shows a muted gray **None** (an alert with no channel can never deliver, so it is worth seeing at a glance).
- **Long lists**: both columns show the first 3 badges and expand on click (`limitList(3)` + `expandableLimitedList()`).
- **Gray vs colored**: location and channel names are normal (colored) badges; the three mode labels and "None" are gray. Gray is decided by a closure, not stored data, so a location a subscriber happens to name "Nationwide" would also render gray — a purely cosmetic, accepted edge case.
- **Eager loading**: the table query loads `location`, `locations`, `notificationChannels` and `products` for the page, so the new columns add no per-row queries. `products` is included because the existing "Alert" column (`AlertRule::displayName()`) already reads it per row — fixing that existing N+1 is cheap here and in scope of touching the query.
- **Not included**: searching or sorting by these columns, a filter by location or channel, and any change to the alert form, actions or other columns.

## Implementation notes

- `App\Enums\MatchingMode::shortLabel(): string` — `Nationwide => 'Nationwide'`, `Wfo => 'Issuing office'`, `CountyZone => 'County/zone'`, and for completeness `Location => 'By location'`, `Polygon => 'By polygon'`, `RiskArea => 'By risk area'` (those three are never shown as labels by the column, since they list locations, but the method must be total).
- `AlertRulesTable`: add two `TextColumn`s.
  - **Locations**: `TextColumn::make('locations')` with `getStateUsing(fn (AlertRule $record): array => …)` returning the list of strings described above; `->badge()`, `->color(fn (string $state): string => in_array($state, [mode short labels..., 'None'], true) ? 'gray' : 'primary')`, `->limitList(3)->expandableLimitedList()`, `->toggleable()`. The state closure reads only already-loaded relations (`location`, `locations`).
  - **Channels**: `TextColumn::make('notificationChannels.label')`, `->label('Channels')`, `->badge()`, `->limitList(3)->expandableLimitedList()`, `->placeholder('None')` (a gray muted placeholder), `->toggleable()`.
  - Eager loading via `->modifyQueryUsing(fn (Builder $query) => $query->with(['location', 'locations', 'notificationChannels', 'products']))` on the table (the resource's `getEloquentQuery()` keeps its `whereBelongsTo(auth()->user())` scoping untouched).
- Only the signed-in subscriber's own alerts appear (the resource scoping is unchanged), and locations/channels shown are the ones attached to those alerts.

## Testing

Pest feature tests (Livewire table state; container run as for other features), extending `tests/Feature/AlertRuleResourceTest.php` or a focused new file next to it:

- Locations: a Weather Alerts rule in `Location` mode with two locations shows both labels in order; a Daily Summary rule shows its single `location` label; a Lightning and a Threshold rule do the same; a Nationwide rule shows "Nationwide"; a WFO rule shows "Issuing office"; a County/Zone rule shows "County/zone"; a location-mode rule with no locations, and a Daily Summary whose `location_id` is null, show "None".
- Channels: a rule with two channels shows both labels; a rule with none shows the "None" placeholder.
- Isolation: another subscriber's alerts, locations and channels never appear.
- Query count: listing several alerts, each with several locations and channels, runs the same number of queries as listing one (no per-row queries).
- `MatchingMode::shortLabel()` returns a non-empty string for every case.
- Browser check (controller, after execution): a throwaway subscriber with a location-mode weather alert (2 locations), a nationwide alert, a daily summary, an alert with 4+ locations or channels (expands past 3), and an alert with no channels; confirm the columns, gray vs colored badges, the expand-on-click behavior, the column toggle, and that the Active toggle and row actions still work. Delete the throwaway data afterwards.

## Rules to record

None expected. If the implementation hits a Filament trap with `limitList` on a computed state or `modifyQueryUsing` on a resource table, add it to `.ai/rules/filament.md`.
