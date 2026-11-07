---
paths:
  - 'app/Filament/Dashboard/Pages/LiveWeather/**'
  - app/Filament/Dashboard/Pages/LiveWeather/Products.php
  - app/Filament/Dashboard/Pages/LiveWeather/Map.php
---

# Live Weather

## Live weather page data methods: public data only, Renderless, validate, never throw
Methods the page JS calls through `$wire` are `#[Renderless]`, validate every argument (types, bbox, site code, day), return a plain "unavailable"/empty payload instead of throwing, and return only public NWS data (never another subscriber's data). Outbound NOAA calls go to the fixed host `https://opengeo.ncep.noaa.gov/` only, with the radar site validated against `NexradSites` before any URL or cache key is built; frame lists are cached 60 s (30 s on failure) and shared. Mesoscale Discussion text is mixed case with a wrapped "Areas affected" line: parse against real fixtures and take expiry from the "VALID" line (24 h month-rollover guard, 3 h default).

## Live products page trusts only the id
Rows are built only by `buildRows()` and read through `listable()` (never `text`, never is_test or replays), so a forged payload adds nothing. `rows` and `categories` are #[Locked]. Data methods are budgeted per user via `Concerns/LimitsDataCalls` (fetchSince/setCategories 120/min, onProductIngested 600/min). The browser no longer calls onProductIngested: it debounces broadcasts (1 s) into one `fetchSince(newestId)` with an INTEGER id (anything else returns []). The "new" highlight is client-side only (8 s, starts when the viewer is at the top).

## Products page: no new-row highlight, the whole row is the link (supersedes the "new highlight" sentence)
The owner removed the new-row tint and "New" badge (f14d364); ignore the earlier "client-side highlight, 8 s" sentence in this file. Only the "N new products" pill (with `pending` and its x-effect reset) tells the viewer about new rows. Each row with an https public page is one stretched anchor (target=_blank, rel=noopener) named by the product; rows without a public page are plain and not clickable.

## Products rows link only when Products::isLinkable() says so
The one check, called from the Blade (supersedes the "https only" wording): an https:// URL always, an http:// URL only when `! app()->isProduction()`, never any other scheme, a relative URL or none. The anchor keeps target=_blank rel=noopener. Row links come from IngestedProduct::viewUrl(), which uses config('wxnotify.product_domain') else APP_URL, so a local APP_URL that differs from the serving host sends links to the wrong server.

## Products page lists only titled products
listable() requires a catalog product with a non-empty name, or a non-blank awipsid, or a non-blank ttaaii (btrim with PHP trim chars), so the generic "Weather product" rows never appear, count as pending, or take cap slots. Keep the condition in listable() only; the broadcast event still announces them.

## Per-viewer map data goes through data-config, scoped to the user
Per-viewer data on the Live map (the My places pins) goes through the server-rendered data-config scoped to auth()->user(), never a $wire method, and never carries anything but name, address, type and position.
