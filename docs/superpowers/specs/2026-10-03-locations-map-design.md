# Locations Map — Design

Source: owner request — like the Traccar Servers page, the Locations page should include a map above the table showing all of the subscriber's locations as markers.

## Goal

Add a "Location map" card above the Locations table: a Leaflet map with one marker per location the subscriber owns. Stationary locations are red; mobile (Traccar-tracked) locations are blue, turning gray when their last position update is more than an hour old. Clicking a marker shows its details and an Edit link. The map is shown to every tier and renders even when the subscriber has no locations.

## Decisions (user-approved, from brainstorming)

- **Marker styling**: stationary = red; mobile = blue, **gray** when stale. Stale uses the Traccar map's existing rule (`GetTraccarDeviceMap::STALE_AFTER_MINUTES`, 60) applied to `locations.mobile_position_updated_at`; a stationary location is never stale. Rejected: one color for everything (a stale mobile location would look live) and the Traccar map's red/gray-only scheme (mobile and stationary indistinguishable without opening the popup).
- **Mobile locations with no position yet** (no `lat`/`lng`, i.e. `! hasFix()`) are **left off the map** and counted in the caption ("N mobile locations have no position yet.").
- **Popup** (opened by clicking a marker): the location's label; its type ("Stationary" / "Mobile"); for a stationary location its address, for a mobile one "Last position update: …" (absolute timestamp plus relative time, as on the Traccar map) and, when gray, the note "No update for over an hour"; and an **Edit** link to that location's edit page (the owner may drop the link — it is an addition beyond the original request).
- **Database only.** Positions of mobile locations are already stored by the Traccar poller, so the map makes **no** Traccar or other external call; a slow Traccar server can never delay the page. Scoped to the signed-in subscriber's own locations.
- **Always shown**, to every tier (Free has one location; the map is useful to all). With zero locations the map still renders (centered on the contiguous US like the Traccar map) with the caption "Add a location to see it here."
- **Refresh**: the browser re-fetches every 60 seconds (cheap, database only) so a mobile location's pin moves as the poller updates it. The map fits all markers on the first load only (`maxZoom: 12`, so a single pin does not zoom in absurdly) and never re-centers afterwards, so a subscriber who panned or zoomed is not yanked back.
- **Shared map JavaScript**: the pin-icon, relative-time and timestamp helpers currently live inside the Traccar map's view. Extract them into a shared Blade partial next to `filament.partials.leaflet-loader` and have **both** maps use it; the Traccar map's behavior must be unchanged. Rejected: copying ~60 lines of JS into a second view.

## Widget and data

- `App\Filament\Dashboard\Resources\Locations\Widgets\LocationsMapWidget` — a Filament `Widget` mirroring `TraccarDevicesMapWidget`: `$view = 'filament.dashboard.widgets.locations-map'`, `$columnSpan = 'full'`, `$isLazy = false`, `canView()` returns `LocationResource::canAccess()` (Filament enforces `canView()` on every hydrate, so the data endpoint is protected the same way as the page). One `#[Renderless] public function locations(): array` returns the payload below.
- Registered in `LocationResource::getWidgets()` (new method, like `TraccarServerResource`) and returned from `ListLocations::getHeaderWidgets()`.
- `App\Actions\Locations\GetLocationMap` — `__invoke(iterable $locations): array{locations: array<int, array<string, mixed>>, unplaced: int}`. For each location with a fix it emits `{key: "<id>", label, type: "stationary"|"mobile", address, lat: float, lng: float, stale: bool, updated_at: ?string (ISO-8601, mobile only), edit_url: string}`; mobile locations without a fix only increment `unplaced`. `edit_url` is `LocationResource::getUrl('edit', ['record' => $location])`. Order: by `label`, then `id`. A stationary location with null coordinates (should not exist) is skipped without error. Nothing is exposed beyond those fields (no user id, no Traccar server/device identifiers, no timezone).
- `LocationsMapWidget::locations()` builds the payload from `auth()->user()->locations()->get()`.

## View

`resources/views/filament/dashboard/widgets/locations-map.blade.php`, structured like `traccar-devices-map.blade.php` (a `wire:ignore` root, `x-data` with a `destroy()` that clears the timer and removes the map, `x-init` calling a global `window.initLocationsMap($el, $wire, state)`, a 380 px map container and a caption line). It includes `filament.partials.leaflet-loader` and the new shared helpers partial. Colors: stationary `#dc2626`, mobile `#2563eb`, stale `#9ca3af`. The popup and caption are built with DOM nodes and `textContent` only — labels and addresses are subscriber-supplied strings; the only HTML is the pin SVG, whose color comes from the constants. The Edit link is an `<a>` created in JS whose `href` is set from `edit_url` (server-generated) and whose text is a constant.

## Non-goals

- Marker clustering; drawing alert coverage areas, polygons or radii.
- Linking table rows to markers or a "locate on map" action; click-to-add-location on the map.
- Live Traccar fetching from this page (positions come from the database).
- An admin-panel version.
- Any change to the Traccar Servers map beyond using the shared helpers.

## Testing

Pest feature tests (container run, as for other features):

- `GetLocationMap`: a stationary location yields the expected shape with `type` "stationary", `stale` false and `updated_at` null; a mobile location with a recent `mobile_position_updated_at` is not stale; one older than the stale threshold is stale; a mobile location with no fix is excluded and counted in `unplaced`; ordering is by label then id; `edit_url` points at the edit page; the payload contains no `user_id`, Traccar ids or timezone. Pin the clock with `Carbon::setTestNow`.
- `LocationsMapWidget`: `locations()` returns only the signed-in subscriber's locations (another user's location is absent); with no locations it returns an empty list and `unplaced` 0; `canView()` is true for a Free subscriber.
- `ListLocations` renders the widget (`assertSeeLivewire(LocationsMapWidget::class)`), including for a subscriber with no locations.
- `TraccarDevicesMapWidgetTest` and `GetTraccarDeviceMapTest` still pass after the shared-helper extraction.
- Browser check (controller, after execution): seed a throwaway subscriber with a stationary location, a fresh mobile, a stale mobile, a mobile with no fix, a location with a label like `<b>x</b>`, and another user's location. Confirm: pins and colors (red / blue / gray), the unplaced caption, the other user's location absent, popup text rendered as plain text, the Edit link navigates to the edit page, the empty state (no locations), the map fits the markers once, and the Traccar Servers map still renders and behaves as before. Delete the throwaway data afterwards.

## Rules to record

Add a short "Locations map" note to `.ai/rules/filament.md` (it covers `app/Filament/**` and `resources/views/filament/**`): the Locations and Traccar maps share one helper partial — new map widgets must use it; map popups/captions use `textContent`, never HTML, for subscriber-supplied strings; the Locations map reads only the database (no external calls).
