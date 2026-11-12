---
paths:
  - resources/js/echo.js
---

# Js

## Echo is optional at runtime
echo.js is a Vite input loaded only by the Products page via @vite, never imported by app.js. Its connection settings come from the page at request time (see the next section; VITE_REVERB_* is only a dev fallback), it never throws, and dispatches `wx-echo-ready`. The page starts Offline and polls once a minute; "Live" requires both a connected socket and a subscribed channel (a refused subscription goes Offline and polls). `destroy()` leaves the channel and clears timers.

## Reverb settings come from the page, VITE_REVERB_* is a dev fallback
echo.js reads window.wxReverbConfig first ({key, host, port, scheme}, written by products.blade.php from config('reverb.public') at request time; host null falls back to location.hostname; forceTLS is scheme === 'https'). It uses import.meta.env.VITE_REVERB_* only when that object is absent. Prebuilt images therefore need no rebuild per host. config('reverb.public') must never contain the app secret. env() stays in config/reverb.php (REVERB_PUBLIC_HOST/PORT/SCHEME).
