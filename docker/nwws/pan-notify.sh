#!/bin/sh
set -eu

# $1 is the absolute path nwws-go-client just wrote, e.g. /products/kkci/kkci_....txt
# Strip the /products/ prefix nwws-go-client's own NWWS_ARCHIVEDIR always
# adds, since the ingestion endpoint resolves paths relative to its own
# configured products root, not as an absolute filesystem path.
path="${1#/products/}"

curl -sf -X POST "http://laravel.test/api/pipeline/nwws-products" \
    -H "Authorization: Bearer ${PIPELINE_WEBHOOK_TOKEN}" \
    -H "Content-Type: application/json" \
    -d "{\"path\":\"${path}\"}"
