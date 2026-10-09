# Mobile Parity Management (alert rules and locations in the app) — Design

Source: owner request — Phase 6, sub-project 4 of the mobile app (foundation, push and location are built; see `2026-10-04-mobile-app-foundation-design.md`, `2026-10-04-mobile-push-design.md` and `2026-10-05-mobile-location-design.md`). The foundation spec named this slice "locations, alert rules and channels in the app, on the same API"; the owner narrowed it to alert rules and locations.

## Goal

A subscriber can create, edit, switch on or off and delete their alert rules, and add, edit and delete their locations, from the app, with the same fields, limits and results as the dashboard. A rule saved in the app is indistinguishable from one saved on the web.

## Decisions (user-approved, from brainstorming)

- **Scope: alert rules + locations.** Channels, Traccar linking and message templates stay on the web. Rejected: rules only; all three (channel types each have their own settings and secrets); read and toggle only.
- **Rule depth: full create and edit, every category** (Weather Alerts, SPC & WPC, Threshold, Daily Summary, Lightning), with the dashboard's fields and tier gates. Rejected: managing existing rules only or creating only the simple kinds.
- **A shared save action.** A new `SaveAlertRule` action holds what the dashboard's Create and Edit page hooks do today; the Filament pages call it and so does the API. Rejected: an API-only copy (the two would drift); a server-driven form definition (a new format both sides would have to implement).
- **Channels in the rule form: pick from the user's existing verified channels.** With none, the form points to Account (turn on push) or the web. A new rule does not pre-select this phone's push channel (the earlier "user picks per rule" ruling stands). Channel creation from the app is out of scope.
- **Stationary locations are positioned by "Use my current position" or an address search**, no map (the map is slice 5). Rejected: current position only; deferring stationary create to the map slice.

## Backend

All routes are in the existing `/api/v1` group with `auth:sanctum`, `ability:app`, `ExtendAppTokenExpiry` and `throttle:app-api`, return Eloquent API Resources (the default `data` wrapper), and are scoped to the token's user (another user's id is a 404). Each operation is an Action class, validation is a Form Request, and activity writes pin the causer with `CauserResolver::withCauser` (`.ai/rules/actions.md`, `.ai/rules/api.md`). No response carries a channel destination or URL, a Traccar credential or a token.

### Alert rules

- `GET /alert-rules`, `GET /alert-rules/{id}`: the rule with its `product_ids`, `location_ids` (or the single `location_id` for the scheduled categories) and `channel_ids`. Unit-bearing values (`threshold_value`, `radius`) come back in the subscriber's unit system with a unit label, like the form's display conversion (`AlertRuleFormUnits::toDisplay`).
- `POST /alert-rules`, `PUT /alert-rules/{id}`: the dashboard form's fields in the subscriber's units. `PUT` replaces the rule's settings as the Edit page does. `PATCH /alert-rules/{id}` takes only `is_active` (the list switch). `DELETE /alert-rules/{id}`.
- **`SaveAlertRule`** takes the validated data and the user and does what `CreateAlertRule` / `EditAlertRule` do today: set `user_id`, flatten SPC & WPC risk levels (`SpcWpcRiskLevels`), convert units with the no-drift guard (`AlertRuleFormUnits::toStorage`), clear `alert_template_id` when the category does not support one, and sync products, channels and locations. The Filament Create and Edit pages call it from their hooks; the dashboard's behaviour does not change and its existing tests are the safety net. Writes are logged with the user as causer.
- **Validation (a Form Request that mirrors the form):** the fields each category requires (fields that do not apply to the category, matching mode or tier are ignored, not refused; see Clarifications); at least one product; products of the right family for the category; a matching mode from the allowed set for the selected products (Weather Alerts: every mode except Risk area; SPC & WPC: Nationwide plus Risk area when a selected product carries risk levels, otherwise Nationwide plus Polygon); locations and channels that belong to the user; county/zone codes and an office code that exist; the lightning radius bounds in the user's units; message length, custom subject and template only for non-Free users and a template only for a category that supports one and that belongs to the user; quiet hours; the name's length.
- **Parity test:** the same scenarios run through the Filament pages and the API assert identical stored rows (every category, Metric and Imperial, SPC risk levels, template clearing).

### Options and lookups (read-only)

- `GET /alert-rule-options`: categories with labels and descriptions; active products grouped by product category with each product's risk-level options; matching modes with their descriptions; threshold variables, directions and timeframes with the unit label for this user; the lightning radius bounds in the user's units; tier flags (message customisation); the user's verified channels (id, label, type) and their templates by category; whether the user may add a location.
- `GET /nws-offices`: the office codes and city/state labels (`cwa_boundaries`).
- `GET /zones?state=&q=`: counties and zones with their UGC code and label, searched by state and text, paged, never the whole table.

### Locations

- `GET /locations`, `GET /locations/{id}`: label, type, coordinates and address, timezone, source, update interval and, for a mobile location, its freshness and a `linked` flag (never `app_token_id`, a Traccar credential or a device id beyond its name).
- `POST /locations`: **stationary only**. The server resolves the timezone (`ResolveTimezoneFromCoordinates`) and the address (the existing reverse-geocode path) as the dashboard does. A new location counts against `User::canCreateLocation()`: at the limit the answer is 422 `{error: 'location_limit'}`, the same as `PUT /location-sharing`. A mobile location is created by turning sharing on (slice 3) or on the web (Traccar).
- `PUT /locations/{id}`: a stationary location's name, timezone and position; a mobile location's name and update interval. Never the type, the source or the Traccar link.
- `DELETE /locations/{id}`: refused with 409 `{error: 'location_in_use', alerts: [...names]}` while `LocationDeletionGuard::alertsUsing()` returns any rule (the same guard the dashboard's delete action uses); a location linked to this or another phone is refused with 409 `{error: 'location_is_a_phone'}` (stop sharing in Account first, or on the web). An unlinked app location and a Traccar one can be deleted.
- `GET /places/search?q=`: forward geocoding for the stationary picker, against the configured Nominatim (`config('filament-pinpoint.leaflet.nominatim_url')` or the admin-set endpoint, behind the same URL guard and pinned request options as reverse geocoding). It follows OSM's usage policy: one request per second app-wide, an identifying User-Agent, results cached by query, no autocomplete (the app sends a search only when the user taps Search), at most five results of `{label, lat, lng}`. Query text is never logged.

### Shared behaviour

- Maintenance mode: the models already block subscriber writes (`MaintenanceModeException`), so a write answers 503 with the dashboard's maintenance message as a **plain-text body, not JSON** (`MaintenanceModeException::render`). The app treats a 503 on a write as "maintenance" and shows that text; it does not try to parse JSON from it.
- A refused write changes nothing (one transaction per save).
- Throttled by `app-api`; the places search has its own tighter limiter.

## App (`mobile/`)

### Tabs and screens

The bottom bar becomes **Alerts · Rules · Locations · Account**, leaving room for the Map tab (slice 5) within five tabs. Every screen is a native component from EDGE elements registered with `Route::native()` (no web view), with the existing palette and icons.

- **Rules**: a list (name, or the generated summary when unnamed; category; an on/off switch per row), a "+" to add, tap to edit.
- **Rule editor**: one scrolling form in the dashboard wizard's four sections, **Category → Set it up → Notify me on → Finishing touches**; **Save**; **Delete** (with confirmation) when editing. "Set it up" depends on the category: Weather Alerts and SPC & WPC show the products grouped by category (collapsible), risk levels where a selected product has them, the matching mode and its location, office or county/zone picker; Threshold shows variable, direction, value in the user's units, timeframe, location and delivery time; Daily Summary a location and a delivery time; Lightning a location and a radius. Channels lists the user's verified channels; with none it points to Account and the web. Message length, custom subject and template appear only for non-Free users. Offices and counties/zones use a search field, not a long list. A location picker offers "Add a location".
- **Locations**: a list (name, type; for a mobile one, whether it is current, stale or has lost its phone), "+", tap to edit.
- **Location editor**: a stationary location has a name, a position, a timezone and the resolved address; the position comes from **Use my current position** (a one-shot read through the geolocation plugin, While-using permission only, separate from the background sharing) or an **address search** (up to five results to choose from), and the editor shows the resolved address before saving. A mobile location edits its name and update interval; an app location is otherwise managed from Account, a Traccar one on the web. Delete shows the guard's message when refused. At the tier limit the "+" explains the limit.
- **Drafts**: each editor keeps a draft in the component and sends nothing until **Save**; backing out with edits asks "Discard changes?". Switching category clears the fields that no longer apply, as the dashboard does.
- **`RuleFormPresenter`**: a plain, testable app-side class that takes the options payload and the draft and returns the visible fields, the allowed matching modes (the same rule as the server's validation) and the risk-level choices. The server re-validates everything and is the authority; 422 errors are mapped to the field they belong to.

### Offline

The rules and locations lists and the options payload are kept in the local cache (wiped on sign-out like the rest) so the screens open instantly and, offline, show the last copy with an "Offline" note; the editors open from cached options. Saving, deleting and toggling need the network, fail with a clear message and keep the draft; there is no write queue. The on/off switch flips immediately and reverts with a message if the server refuses.

### Interaction with slice 3

The Locations list shows the phone's own location like any other. Deleting it is refused (stop sharing in Account first). Renaming it is allowed and does not change the re-link rule, which matches by device name only among unlinked app locations.

## Testing

Pest feature tests (backend in the container, app on the host):

- **Backend**: each endpoint's authentication, ability and ownership (another user's rule, location, channel or template is refused or a 404); the Form Request's rules per category (required and forbidden fields, allowed matching modes, product family, risk levels, lightning bounds in Metric and Imperial, tier-gated fields, template category); `SaveAlertRule` (units without drift, risk-level flatten, template clearing, pivot syncs, activity causer); the parity test through both front ends; the options payload per tier and unit system; the zones and offices lookups; location create (limit 422, timezone and address resolution, stationary only), update (mobile versus stationary fields) and delete (in use, a phone's location, unlinked app location, Traccar location); the places search (rate limit, cache, the URL guard, no logging of the query, result cap); a write during maintenance mode; no secret in any response.
- **App**: a fake network for every screen state (list, empty, offline, error, saving, 422 per field, limit reached); `RuleFormPresenter` per category and per mode; draft discard; optimistic toggle and revert; the current-position and address-search flows against the geolocation fake from slice 3; the sign-out wipe of the new cache; the plugin guard rows; `native:validate` clean.
- **First plan task**: confirm the EDGE element vocabulary has the inputs the forms need (switch, text, number, time, multi-select, search field). If one is missing, rule on a workaround before building on it.
- **Device check** (controller, after execution, Android emulator): create, edit, switch off and delete a rule of each category and a stationary location (by current position and by address search); see each change on the dashboard and a rule saved in the app match as the dashboard would; the guarded delete messages; offline behaviour. iOS reported unverified.

## Clarifications settled while planning

- `GET /places/reverse?lat=&lng=` is added to the places endpoints: the dashboard fills a stationary location's address from the browser map, so the app (no map yet) asks the server for the address of its current position before saving. `POST /locations` takes the `address` the app resolved (at most 255 characters) and resolves only the timezone itself when none is sent.
- The options payload lists all of the user's channels with a `verified` flag, like the dashboard (which labels unverified ones "(unverified)"); the rule form marks them the same way. Hiding them would make a channel an existing rule already uses unselectable.
- A retired or draft product an existing rule still uses passes validation (the dashboard does not validate products at all, only that one is chosen) and the app keeps its id in the draft although it lists active products only.
- `SaveAlertRule` clears the columns and pivots that belong to another category, so a category change leaves nothing stale; the dashboard's Edit page now does the same.
- `select` and `radio-group` elements take labels only, so the app keeps a label-to-id map for id-valued pickers and numbers duplicate names ("Home", "Home (2)").
- The stationary location's timezone is chosen from the US zones (or left automatic, resolved from the position by the server).
- Fields that do not apply to the category, matching mode or tier are ignored, not refused, as the dashboard ignores hidden fields.
- The lightning radius is a whole number in the subscriber's distance unit (the stored column is an integer); a fraction is refused.

## Rules to record

- `.ai/rules/api.md`: rule and location writes go through `SaveAlertRule` and the location actions, which the dashboard shares; the API speaks the subscriber's unit system; location delete honours `LocationDeletionGuard` and never removes a phone's linked location; the places search never logs the query.
- `.ai/rules/actions.md` (or the Filament rules): the Create and Edit pages of alert rules delegate persistence to `SaveAlertRule`; a change to the rule's save behaviour belongs in the action.

## Out of scope

Channels management (create, verify, edit), Traccar server and device linking, message template management, the map and Heads Up, Public API keys, duplicating a rule, bulk actions, sending a test alert from the app, a write queue for offline edits, and iOS device verification.
