---
paths:
  - app/Providers/Filament/DashboardPanelProvider.php
---

# Providers Filament

## Dashboard navigation group order is explicit
The group order is set by `->navigationGroups([...])` in DashboardPanelProvider: Live Weather, Alerting, Tools, Support (Dashboard is ungrouped and first). A new `$navigationGroup` label must be added there or it sorts last.
