#!/bin/sh
# Runs the retention cleanup in the background (now, then hourly) and hands
# PID 1 to the Go client so it keeps its own signal handling.
(
    while true; do
        /cleanup-products.sh || true
        sleep 3600
    done
) </dev/null &

exec /nwws-go-client "$@"
