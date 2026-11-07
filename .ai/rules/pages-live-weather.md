---
paths:
  - 'resources/views/filament/dashboard/pages/live-weather/**'
  - resources/views/filament/dashboard/pages/live-weather/products.blade.php
---

# Pages Live Weather

## Live map front end: stacking and Leaflet pane facts
Filament z-index: topbar z-30, sidebar z-30 (lg:z-20), modals z-40; the map's warning drawer uses z-[25]. Leaflet panes: tile 200, overlay 400; the custom radar/outlook panes use 350/390/400/410, so warnings sit above outlooks and below popups. JS modules (`wxLiveMapRadar/Warnings/Layers`) start in try/catch, count requests to drop stale answers, use localStorage only in try/catch, write external text with `textContent` only, and tear down with the page. After changing these views run `npm run build` (public/build goes stale).

## Products page Pause/Resume
While `paused`, gapFill()/startPolling() are no-ops, the debounce and retry timers and the poll are cleared, and events only increment `missed` (never a request); resume() makes ONE gapFill(newestId) then setStatus(status). The count sits in an aria-hidden span so the chip announces only Paused/Live. Alpine x-show must wrap `<x-filament::icon>` in a span because the component drops it. Known gap: a fetch already in flight when Pause is clicked can still land.

## Products toolbar: one row
The category chips ([data-category-chips]) and the status chip + Pause button ([data-live-controls], ml-auto) share one flex-wrap row ([data-products-toolbar]) inside the x-data root; keep it inside the Alpine scope.

## Live chip dot pulses only while live
The dot (`data-live-dot`) pulses via `.wx-live-dot-pulse` only when `! paused && status === 'live'`, so a pulse always means live. A reduced-motion rule turns the animation off, and the dot stays aria-hidden.
