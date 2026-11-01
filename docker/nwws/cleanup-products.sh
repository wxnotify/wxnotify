#!/bin/sh
# Deletes product FILES older than NWWS_RETENTION_DAYS * 24 hours (by mtime)
# under NWWS_PRODUCTS_DIR. Never deletes directories, never follows symlinks.
set -eu

DIR="${NWWS_PRODUCTS_DIR-/products}"
DAYS="${NWWS_RETENTION_DAYS-7}"

# Digits only, at most 4 of them, and at least 1; anything else is a typo.
VALID=1
case "$DAYS" in
    '' | *[!0-9]*) VALID=0 ;;
esac
if [ "$VALID" -eq 1 ] && [ "${#DAYS}" -gt 4 ]; then
    VALID=0
fi
if [ "$VALID" -eq 1 ]; then
    # Strip leading zeros so the shell never reads the value as octal.
    while :; do
        case "$DAYS" in
            0?*) DAYS="${DAYS#0}" ;;
            *) break ;;
        esac
    done
    if [ "$DAYS" -lt 1 ]; then
        VALID=0
    fi
fi
if [ "$VALID" -eq 0 ]; then
    echo "nwws retention: invalid NWWS_RETENTION_DAYS '$DAYS' (need a whole number from 1 to 9999), using 7" >&2
    DAYS=7
fi

while :; do
    case "$DIR" in
        ?*/) DIR="${DIR%/}" ;;
        *) break ;;
    esac
done

if [ -z "$DIR" ] || [ "$DIR" = "/" ] || [ ! -d "$DIR" ]; then
    echo "nwws retention: refusing '$DIR' (empty, root or not a directory), nothing deleted" >&2
    exit 0
fi

# find deletes, then prints one fixed dot per file it actually deleted, so
# the count is exact even for names containing newlines.
COUNT=$(find "$DIR" -type f -mmin +$((DAYS * 1440)) -delete -exec echo . \; | wc -l || true)
COUNT=$((${COUNT:-0} + 0))

echo "nwws retention: deleted $COUNT file(s) older than $DAYS day(s)"
