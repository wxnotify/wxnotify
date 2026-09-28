#!/bin/sh
set -eu

# $1 is the absolute path nwws-go-client just wrote, e.g. /products/kkci/kkci_....txt
# Strip the /products/ prefix nwws-go-client's own NWWS_ARCHIVEDIR always
# adds, since the ingestion endpoint resolves paths relative to its own
# configured products root, not as an absolute filesystem path.
path="${1#/products/}"
webhook_url="${NWWS_WEBHOOK_URL:-http://laravel.test/api/pipeline/nwws-products}"

curl -sS -f --max-time 10 -X POST "$webhook_url" \
    -H "Authorization: Bearer ${PIPELINE_WEBHOOK_TOKEN}" \
    -H "Content-Type: application/json" \
    -d "{\"path\":\"${path}\"}"
