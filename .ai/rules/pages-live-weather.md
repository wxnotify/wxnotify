---
paths:
  - 'resources/views/filament/dashboard/pages/live-weather/**'
---

# Pages Live Weather

## Live map front end: stacking and Leaflet pane facts
Filament z-index: topbar z-30, sidebar z-30 (lg:z-20), modals z-40; the map's warning drawer uses z-[25]. Leaflet panes: tile 200, overlay 400; the custom radar/outlook panes use 350/390/400/410, so warnings sit above outlooks and below popups. JS modules (`wxLiveMapRadar/Warnings/Layers`) start in try/catch, count requests to drop stale answers, use localStorage only in try/catch, write external text with `textContent` only, and tear down with the page. After changing these views run `npm run build` (public/build goes stale).
