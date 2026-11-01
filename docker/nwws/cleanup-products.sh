#!/bin/sh
# Deletes product FILES older than NWWS_RETENTION_DAYS (by mtime) under
# NWWS_PRODUCTS_DIR. Never deletes directories, never follows symlinks.
set -eu

DIR="${NWWS_PRODUCTS_DIR:-/products}"
DAYS="${NWWS_RETENTION_DAYS-7}"

case "$DAYS" in
    '' | *[!0-9]* | 0 | 0[0]*)
        echo "nwws retention: invalid NWWS_RETENTION_DAYS '$DAYS' (need a whole number >= 1), using 7" >&2
        DAYS=7
        ;;
esac

if [ ! -d "$DIR" ]; then
    echo "nwws retention: $DIR is not a directory, nothing deleted" >&2
    exit 0
fi

# Failure to list is not fatal; failure to delete one file is not fatal.
OLD=$(find "$DIR" -type f -mtime +"$DAYS" 2>/dev/null || true)

COUNT=0
if [ -n "$OLD" ]; then
    while IFS= read -r FILE; do
        if rm -f -- "$FILE" 2>/dev/null; then
            COUNT=$((COUNT + 1))
        fi
    done <<EOT
$OLD
EOT
fi

echo "nwws retention: deleted $COUNT file(s) older than $DAYS day(s)"
