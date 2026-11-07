---
paths:
  - 'app/Filament/Dashboard/Pages/LiveWeather/**'
  - app/Filament/Dashboard/Pages/LiveWeather/Products.php
---

# Live Weather

## Live weather page data methods: public data only, Renderless, validate, never throw
Methods the page JS calls through `$wire` are `#[Renderless]`, validate every argument (types, bbox, site code, day), return a plain "unavailable"/empty payload instead of throwing, and return only public NWS data (never another subscriber's data). Outbound NOAA calls go to the fixed host `https://opengeo.ncep.noaa.gov/` only, with the radar site validated against `NexradSites` before any URL or cache key is built; frame lists are cached 60 s (30 s on failure) and shared. Mesoscale Discussion text is mixed case with a wrapped "Areas affected" line: parse against real fixtures and take expiry from the "VALID" line (24 h month-rollover guard, 3 h default).

## Live products page trusts only the id
Rows are built only by `buildRows()` and read through `listable()` (never `text`, never is_test or replays), so a forged payload adds nothing. `rows` and `categories` are #[Locked]. Data methods are budgeted per user via `Concerns/LimitsDataCalls` (fetchSince/setCategories 120/min, onProductIngested 600/min). The browser no longer calls onProductIngested: it debounces broadcasts (1 s) into one `fetchSince(newestId)` with an INTEGER id (anything else returns []). The "new" highlight is client-side only (8 s, starts when the viewer is at the top).
