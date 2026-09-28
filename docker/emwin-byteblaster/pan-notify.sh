#!/bin/sh
# Per-file hook for npemwind's panfilter: pan-notify.sh <absolute path of the file just stored>.
# Plain .txt products (npemwind unzips and names them uniquely itself) are posted by their own
# relative path. A .zip that reaches us is unzipped defensively into UNIQUE files under
# $ROOT/delivered/. Never fails npemwind: every problem is logged and skipped.
set -u

ROOT="${EMWIN_FILES_ROOT:-/var/npemwin/files}"
WEBHOOK_URL="${EMWIN_WEBHOOK_URL:-http://pipeline-api:8080/api/pipeline/emwin-products}"
MAX_BYTES="${EMWIN_MAX_UNZIP_BYTES:-5242880}"
MAX_MEMBERS="${EMWIN_MAX_UNZIP_MEMBERS:-50}"
HEARTBEAT="${EMWIN_HEARTBEAT_FILE:-/tmp/emwin-last-product}"

log() {
    level=$1
    shift
    if command -v logger >/dev/null 2>&1; then
        logger -t emwin-pan -p "user.$level" -- "$*"
    else
        echo "emwin-pan [$level] $*" >&2
    fi
}

[ $# -ge 1 ] || { log err "called without a file path"; exit 0; }
src=$1
[ -f "$src" ] || exit 0

# EMWIN text ends its lines with CR CR LF, which reads as a blank line after every line. Rewrites
# $1 in place so every line ends in a single LF, before the file is posted. Files without a CR are
# left alone. A failure is logged and the product is still delivered.
normalize_line_endings() {
    nl_file=$1
    nl_cr=$(printf '\r')
    grep -q "$nl_cr" "$nl_file" 2>/dev/null || return 0
    if ! sed -i 's/\r*$//' "$nl_file" 2>/dev/null; then
        log err "could not normalize the line endings of ${nl_file#"$ROOT"/}; delivering it as received"
    fi
    return 0
}

# Delivers one already-placed file: $1 = absolute path under $ROOT.
deliver() {
    file=$1
    rel=${file#"$ROOT"/}
    case $rel in
        *[!A-Za-z0-9._/-]*) log err "refusing to deliver $rel: unsafe characters in the file name"; return 1 ;;
    esac
    normalize_line_endings "$file"
    if err=$(curl -sS -f --max-time 10 -X POST "$WEBHOOK_URL" \
        -H "Authorization: Bearer ${PIPELINE_WEBHOOK_TOKEN:-}" \
        -H "Content-Type: application/json" \
        -d "{\"path\":\"$rel\"}" 2>&1); then
        : > "$HEARTBEAT"
        log info "delivered $rel"
        return 0
    fi
    log err "POST failed for $rel: $err"
    return 1
}

stamp=$(date -u +%Y%m%dT%H%M%S)
seq=0

# Places one unzipped member ($1 = extracted file, $2 = member name) under delivered/ with a unique name, then delivers it.
place_and_deliver() {
    from=$1
    name=$2
    seq=$((seq + 1))
    base=${name%.*}
    reldir=$(dirname "${src#"$ROOT"/}")
    [ "$reldir" = "." ] && reldir=""
    destdir="$ROOT/delivered${reldir:+/$reldir}"
    if ! mkdir -p "$destdir"; then
        log err "cannot create $destdir"
        return 1
    fi
    dest="$destdir/${base}_${stamp}_$$_${seq}.txt"
    if ! cp "$from" "$dest"; then
        log err "cannot copy $from to $dest"
        return 1
    fi
    chmod 644 "$dest"
    deliver "$dest"
}

handle_zip() {
    if ! listing=$(unzip -l "$src" 2>&1); then
        log err "cannot read zip $src: $listing"
        return 0
    fi
    summary=$(printf '%s\n' "$listing" | awk '
        $1 ~ /^[0-9]+$/ && $2 ~ /^[0-9-]+$/ && $3 ~ /^[0-9]+:[0-9]+$/ { bytes += $1; n++ }
        END { print bytes + 0, n + 0 }')
    bytes=${summary% *}
    members=${summary#* }
    if [ "$members" -eq 0 ]; then
        log err "zip $src has no members"
        return 0
    fi
    if [ "$members" -gt "$MAX_MEMBERS" ] || [ "$bytes" -gt "$MAX_BYTES" ]; then
        log err "refusing zip $src: $members members / $bytes bytes exceeds the limits ($MAX_MEMBERS / $MAX_BYTES)"
        return 0
    fi

    tmp=$(mktemp -d "${TMPDIR:-/tmp}/emwin-unzip.XXXXXX") || { log err "cannot create a temp dir for $src"; return 0; }
    # -j drops directory components (no path traversal), -C matches *.txt case-insensitively.
    err=$(unzip -j -o -q -C "$src" '*.txt' -d "$tmp" 2>&1)
    rc=$?
    if [ "$rc" -gt 1 ]; then
        log err "unzip failed for $src (exit $rc): $err"
        rm -rf "$tmp"
        return 0
    fi

    found=0
    for extracted in "$tmp"/*; do
        [ -f "$extracted" ] || continue
        found=1
        place_and_deliver "$extracted" "$(basename "$extracted")" || true
    done
    [ "$found" -eq 1 ] || log err "zip $src contained no text members"
    rm -rf "$tmp"
}

case $src in
    *.[Zz][Ii][Pp]) handle_zip ;;
    *.[Tt][Xx][Tt]) deliver "$src" || true ;;
    *) : ;;
esac

exit 0
