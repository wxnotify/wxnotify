#!/bin/sh
set -u

DELIVERED=/var/npemwin/files/delivered
RESTART_FLAG=/tmp/emwin-restart-requested
REFRESH_HOURS="${EMWIN_SERVERS_REFRESH_HOURS:-6}"
RETENTION_DAYS="${EMWIN_RETENTION_DAYS:-7}"

# A container restart keeps the writable layer: drop stale state so rsyslogd starts and no phantom restart is honoured.
rm -f /run/rsyslogd.pid "$RESTART_FLAG"

rsyslogd -n -f /etc/rsyslog-emwin.conf &
i=0
while [ ! -S /dev/log ] && [ "$i" -lt 50 ]; do sleep 0.1; i=$((i + 1)); done

/usr/local/bin/refresh-servers.sh
if [ "$?" -eq 1 ]; then
    logger -t emwin -p user.warning "initial server refresh failed; using the existing list"
fi

(
    while true; do
        sleep $((REFRESH_HOURS * 3600))
        /usr/local/bin/refresh-servers.sh
        if [ "$?" -eq 10 ]; then
            touch "$RESTART_FLAG"
            pkill -TERM -f 'npemwind -F'
        fi
        find "$DELIVERED" -type f -mtime +"$RETENTION_DAYS" -delete 2>/dev/null
    done
) &

DAEMON_PID=""
trap '[ -n "$DAEMON_PID" ] && kill "$DAEMON_PID" 2>/dev/null; exit 0' TERM INT

while true; do
    rm -f /var/run/npemwin/npemwind.pid
    runuser -u noaaport -- /usr/local/sbin/npemwind -F &
    DAEMON_PID=$!
    wait "$DAEMON_PID"
    status=$?
    if [ -f "$RESTART_FLAG" ]; then
        rm -f "$RESTART_FLAG"
        logger -t emwin -p user.info "restarting npemwind with the refreshed server list"
        continue
    fi
    exit "$status"
done
