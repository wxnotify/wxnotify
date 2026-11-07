---
paths:
  - 'app/Filament/Dashboard/Pages/LiveWeather/**'
---

# Live Weather

## Live weather page data methods: public data only, Renderless, validate, never throw
Methods the page JS calls through `$wire` are `#[Renderless]`, validate every argument (types, bbox, site code, day), return a plain "unavailable"/empty payload instead of throwing, and return only public NWS data (never another subscriber's data). Outbound NOAA calls go to the fixed host `https://opengeo.ncep.noaa.gov/` only, with the radar site validated against `NexradSites` before any URL or cache key is built; frame lists are cached 60 s (30 s on failure) and shared. Mesoscale Discussion text is mixed case with a wrapped "Areas affected" line: parse against real fixtures and take expiry from the "VALID" line (24 h month-rollover guard, 3 h default).
