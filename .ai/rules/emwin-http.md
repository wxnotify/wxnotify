---
paths:
  - 'docker/emwin-http/**'
---

# Emwin Http

## EMWIN HTTP client invariants: retry contract, permanent rejections, posted path
A file is marked seen only after the pipeline accepts it (2xx) or permanently rejects it (400/410/413/422); validators (Last-Modified/ETag) are recorded only when no POST failed. 404 is deliberately retryable (it also means the shared volume is misconfigured), and a cycle where EVERY posted file was permanently rejected is a failure, so a systematic pipeline fault can never silently drop the feed while health stays green. storage_dir must be a strict subdirectory of /emwin-products; the posted path is its relative form (default `http/<name>`), because Laravel resolves posted paths under emwin_products_path and keys rows by file_path (never post a path whose content a later issuance can overwrite). The client always sends `feed: "http"` so its heartbeat (`emwin_http`) can never mask a dead Byteblaster feed; state and health markers live in their own /state volume, never on the volume Laravel reads. Tests must never touch the network (httptest only).
