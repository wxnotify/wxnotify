---
paths:
  - 'docker/nwws/**'
---

# Nwws

## The NWWS container deletes product files older than NWWS_RETENTION_DAYS (default 7)
docker/nwws/entrypoint.sh runs cleanup-products.sh once at start and then hourly in a background loop, then execs the Go client so it stays PID 1. The script deletes regular FILES only (never directories, symlinks or ingested_products rows) older than NWWS_RETENTION_DAYS * 24 hours by mtime (`-mmin`, an exact cutoff). find does the deleting and the counting (`-delete -exec echo .`), never a shell loop over file names, so a newline in a name cannot delete the wrong path. An invalid value (not digits, over 4 digits, below 1) warns on stderr and falls back to 7; an empty, `/` or missing NWWS_PRODUCTS_DIR warns, exits 0 and deletes nothing. Keep it POSIX/busybox compatible (the image is alpine; the Pest test runs it under GNU sh, and busybox counts a failed delete as a deleted file, so the logged count can be high).
DANGER: the front-end container that runs the tests mounts the LIVE nwws-products volume read-write at /products. Every test must set NWWS_PRODUCTS_DIR to a temp directory, and nobody should run cleanup-products.sh by hand in that container. The first run after deploy removes every file older than a week, which cannot be undone; the app keeps the product text in ingested_products, so nothing reads these files after ingest.

## With NWWS_USERNAME or NWWS_PASSWORD empty the container idles, it does not exit
docker/nwws/entrypoint.sh checks both first, before the cleanup loop, and if either is empty logs a notice to stderr and sleeps (a TERM/INT trap exits 0 so `docker stop` is instant). Do not "fix" this to `exit 0`: the compose files use `restart: unless-stopped`, which restarts a container even after a clean exit, so exiting would crash-loop it again (this was the 0.0.1-rc.3 behaviour). tests/Feature/NwwsEntrypointScriptTest.php covers it; its test env must unset NWWS_* with `false`, because the child inherits the test container's environment.
