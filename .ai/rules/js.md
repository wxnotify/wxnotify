---
paths:
  - resources/js/echo.js
---

# Js

## Echo is optional at runtime
echo.js is a Vite input loaded only by the Products page via @vite, never imported by app.js. It reads only VITE_REVERB_APP_KEY/HOST/PORT/SCHEME (build-time values: a build for one environment points at that host), never throws, and dispatches `wx-echo-ready`. The page starts Offline and polls once a minute; "Live" requires both a connected socket and a subscribed channel (a refused subscription goes Offline and polls). `destroy()` leaves the channel and clears timers.
