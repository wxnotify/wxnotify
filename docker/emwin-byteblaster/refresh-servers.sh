#!/bin/sh
# Scrapes the community EMWIN Byteblaster server list into npemwin's servers.conf.
# Exit codes: 0 unchanged, 10 changed (file replaced), 1 failed (file untouched).
set -u

PAGE_URL="${EMWIN_SERVERS_URL:-https://weathermessage.net/Support/EMWINInternetStatus.aspx}"
SERVERS_CONF="${EMWIN_SERVERS_CONF:-/usr/local/etc/npemwin/servers.conf}"
MIN_SERVERS="${EMWIN_MIN_SERVERS:-3}"

log() {
    level=$1
    shift
    if command -v logger >/dev/null 2>&1; then
        logger -t emwin-servers -p "user.$level" -- "$*"
    fi
    echo "emwin-servers [$level] $*"
}

tmp=$(mktemp "${SERVERS_CONF}.XXXXXX") || { log err "cannot create a temp file next to $SERVERS_CONF"; exit 1; }
page="$tmp.page"
trap 'rm -f "$tmp" "$page" "$tmp.err"' EXIT

if ! curl -fsS --max-time 30 "$PAGE_URL" -o "$page" 2>"$tmp.err"; then
    log err "could not fetch $PAGE_URL: $(cat "$tmp.err")"
    exit 1
fi

# One table row per line, then pull the three <td> cells out of each: address/host, name, port.
tr '\n\r\t' '   ' < "$page" \
    | sed 's#</tr>#</tr>\
#g' \
    | awk '
        {
            line = $0; n = 0
            while (match(line, /<td[^>]*>[^<]*<\/td>/)) {
                cell = substr(line, RSTART, RLENGTH); line = substr(line, RSTART + RLENGTH)
                sub(/^<td[^>]*>/, "", cell); sub(/<\/td>$/, "", cell)
                gsub(/&nbsp;/, "", cell); gsub(/^ +| +$/, "", cell)
                c[++n] = cell
            }
            if (n == 3 && c[1] ~ /^[A-Za-z0-9.-]+$/ && c[3] ~ /^[0-9]+$/ && c[3] + 0 >= 1 && c[3] + 0 <= 65535) {
                print c[1] "\t" c[3]
            }
        }' \
    | awk '!seen[$0]++' > "$tmp"

count=$(wc -l < "$tmp" | tr -d ' ')
if [ "$count" -lt "$MIN_SERVERS" ]; then
    log err "found only $count servers (need at least $MIN_SERVERS); keeping the current list"
    exit 1
fi

if [ -f "$SERVERS_CONF" ] && cmp -s "$tmp" "$SERVERS_CONF"; then
    log info "server list unchanged ($count servers)"
    exit 0
fi

chmod 644 "$tmp"
mv "$tmp" "$SERVERS_CONF"
log info "server list updated ($count servers)"
exit 10
