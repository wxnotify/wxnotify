#!/bin/sh
# Without an NWWS-OI account the client cannot run, and exiting would only make
# restart: unless-stopped restart it in a loop. Say why and idle instead (SIGTERM
# stops it at once). This runs before the cleanup loop below, so an idle container
# touches no files.
if [ -z "${NWWS_USERNAME:-}" ] || [ -z "${NWWS_PASSWORD:-}" ]; then
    echo "NWWS_USERNAME and NWWS_PASSWORD are not both set: the NWWS feed is disabled and this container will idle. Set both in .env and recreate it to enable the feed." >&2
    trap 'exit 0' TERM INT
    while true; do
        sleep 3600 &
        wait $!
    done
fi

# Runs the retention cleanup in the background (now, then hourly) and hands
# PID 1 to the Go client so it keeps its own signal handling.
(
    while true; do
        /cleanup-products.sh || true
        sleep 3600
    done
) </dev/null &

exec /nwws-go-client "$@"
