---
paths:
  - resources/js/echo.js
---

# Js

## Echo is optional at runtime
echo.js is a Vite input loaded only by the Products page via @vite, never imported by app.js. It reads only VITE_REVERB_APP_KEY/HOST/PORT/SCHEME (build-time values: a build for one environment points at that host), never throws, and dispatches `wx-echo-ready`. The page starts Offline and polls once a minute; "Live" requires both a connected socket and a subscribed channel (a refused subscription goes Offline and polls). `destroy()` leaves the channel and clears timers.

## Reverb settings come from the page, VITE_REVERB_* is a dev fallback
echo.js reads window.wxReverbConfig first ({key, host, port, scheme}, written by products.blade.php from config('reverb.public') at request time; host null falls back to location.hostname; forceTLS is scheme === 'https'). It uses import.meta.env.VITE_REVERB_* only when that object is absent. This supersedes the older "build-time VITE_REVERB_*" note above, so prebuilt images need no rebuild per host. config('reverb.public') must never contain the app secret. env() stays in config/reverb.php (REVERB_PUBLIC_HOST/PORT/SCHEME).
