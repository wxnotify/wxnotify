---
paths:
  - 'app/Actions/Map/**'
---

# Map

## ActiveWarningAreas holds the shared warning SQL (supersedes "GetMapWarnings selects through active_wwa_areas")
The candidate / zone_boundary / resolved CTEs live in `ActiveWarningAreas::ctes()` and are used by BOTH `GetMapWarnings` (mobile API, anchors + radius) and `GetLiveWarnings` (web Live map, bbox + event filter). Change the SQL once there; the rule that extents come from `active_wwa_areas` (never `active_wwas.ugc_codes`/`polygon`) still holds. Short-fuse kinds live in `LiveWarningKinds`; bbox is clamped to 120x60 degrees and results capped by `wxnotify.map.max_live_warnings`. SPC watches: one row per WFO plus a KWNS row; the WFO row is authoritative, KWNS only a fallback, merged to one event.
