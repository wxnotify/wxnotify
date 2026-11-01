# Product Storage admin page — design

## Why

WxNotify will run on cloud servers with a fixed disk. Every NWS product is written to disk as a file (NWWS to the `nwws-products` volume, EMWIN Byteblaster and EMWIN HTTP to the `emwin-products` volume), and the product volumes only grow: EMWIN files are cleaned up after `EMWIN_RETENTION_DAYS` (default 7), but nothing cleans up the NWWS files. On the dev machine that is 5.9 GB of NWWS files (about 350,000) and 505 MB of EMWIN files (about 82,000). Staff need one page that answers: how full is the disk, how much of it is product files, and where is that space going.

## What the owner decided

- **Files only.** The database is on a different server in the cloud. Database storage is out of scope.
- **Staff-only page in the Admin panel**, under the `Operations` navigation group beside Pipeline Control.
- **Disk gauge:** the disk that holds the product volumes is X% full (used, free and total). Colour bands: normal below 80%, amber from 80%, red from 90%.
- **Used space is split** into NWWS files, EMWIN-Byteblaster files, EMWIN-HTTP files and "everything else on that disk".
- **Distribution charts:** space by source, and space by product type, shown two ways: by catalog group and by file kind (text vs images).
- **Unmatched bucket:** a file that matches no database row (images, leftovers from deleted rows) is counted in an "Unmatched" bucket.
- **View-only.** The page never deletes or cleans up files.
- **No alerts yet.** Only colours at 80% and 90%. Alerts are a later request.

## My calls (not yet confirmed by the owner)

- A scheduled scan runs hourly and stores its totals. The page reads the latest snapshot and shows "as of <time>". A "Scan now" button queues a scan on demand.
- Snapshots are kept (one row per scan, plus its per-bucket rows) so a growth-over-time chart is possible. Old snapshots are pruned after a retention window (default 90 days).
- The gauge is live: `disk_free_space()` / `disk_total_space()` on the volume path, read on page load. No `df` command.
- The scan runs on the long queue (`long`, 900 s timeout), the same worker the shapefile scan uses, so it never delays deliveries.
- "Catalog group" is `Product::$family` where a file's row has a catalog product. Most rows have none (675,103 of 706,134 on the dev database have `product_id` null), so the rest is grouped by the WMO data-type letter pair at the start of `ttaaii`, labelled plainly (for example "Other: forecasts (FZ)"). A row with neither goes to "Unclassified".
- File kind: text (`.txt`, `.TXT`, `txt.N` and any file read as a product), images (`.gif`, `.jpg`, `.png`), other.

## More calls, settled after the builder check (mine, not the owner's)

- **Gauges.** The scan compares the two roots' filesystems (`stat` device id). One filesystem: one gauge, with "everything else" = disk used minus the product bytes on it. Two filesystems: one gauge per filesystem, each with its own "everything else". The page never adds figures from two disks together.
- **Matching.** `ingested_products.file_path` is unique (so indexed) and EMWIN-HTTP rows use the same `emwin/` + relative path as Byteblaster (the HTTP client posts `http/<name>`, giving `emwin/http/<name>`). Match by streaming `file_path, product_id, ttaaii` from the table in chunks and looking files up in memory, or by batched `whereIn`; the builder picks by measurement. Never select `text`.
- **Buckets.** Every dimension (source, catalog group, file kind) sums to the same total of product bytes; a test pins this. "Unmatched" (no row) is a bucket in the catalog-group dimension only. In the file-kind dimension a file is classified by extension alone, whether or not it has a row.
- **Growth chart.** The line is total product bytes per source over time, plus disk % used from the scan row. Per-group history is not charted. Catalog-group buckets store the top 20 groups plus "Other" so the table stays small.
- **Stale.** A snapshot older than 3 hours, or a latest scan that failed, is flagged on the page.
- **Authorisation.** Reuse Pipeline Control's access gate for viewing and for "Scan now".
- **Oldest file.** The oldest modification time across all scanned product files, stored on the scan row.

## Goals

1. A staff member can open one page and see disk % full, product-file share of it, and the largest contributors.
2. The numbers are honest about their age and about what they cannot see.
3. Scanning ~450,000 files never blocks the web request, deliveries or the pipeline.

## Non-goals and anti-goals

- No database size, no per-table figures.
- No purge, archive or retention controls; no change to the NWWS or EMWIN cleanup.
- No alerts, emails or notifications.
- Not a monitoring tool: it must never make a gauge look healthy when the path could not be measured (a missing volume shows "unavailable", not 0%).

## How it works

**Measuring.** A scan walks each product root (`config('services.pipeline.products_path')` for NWWS, `config('services.pipeline.emwin_products_path')` for EMWIN) and sums file sizes. A file's source is its root, with EMWIN-HTTP being anything under `<emwin root>/http/`. The scan must not follow symlinks and must tolerate files vanishing mid-walk (the EMWIN cleanup runs concurrently).

**Classifying.** To place a file by type the scan matches it to its `ingested_products.file_path` (NWWS: path relative to the NWWS root; EMWIN: `emwin/` + path relative to the EMWIN root) and reads the row's `product_id` and `ttaaii`. This is done in batches by key; it must not run one query per file and must not load the `text` column.

**Storing.** One `product_storage_scans` row per scan (started/finished, status, per-source totals, file count, disk used/total at scan time), plus bucket rows (scan, dimension: source / catalog group / file kind, label, bytes, file count). A failed or interrupted scan is recorded as failed and the page keeps showing the last successful one.

**Page.** A Filament Admin page with: the live gauge; stat tiles (product files total, share of disk, file count, oldest file age, last scan time and status); a doughnut or bar for space by source; two charts for type (catalog group, file kind); a growth line over snapshots; and the "Scan now" button. Charts follow the existing Admin widget conventions (`AlertRulesByCategoryChart`).

## Left to the builder

Table and class names, chart types, the retention config key, the exact catalog-group labels, how the walk is made fast (a shell `find` is acceptable if PHP is too slow, but the choice must be justified by measurement on the dev volumes), and where the thresholds live (constants are fine; they are not a setting yet).

## Edge cases the design must cover

- A volume path missing or unreadable: the gauge and the source show "unavailable", never zero.
- Both product roots on one filesystem (dev) or on different ones (the gauge reads the NWWS root's filesystem and says so if the EMWIN root is on another).
- A scan still running when the next is due: skip, never overlap.
- A scan failing halfway: last good snapshot stays on the page, marked with the failed attempt.
- No snapshot yet (fresh install): the page says so and offers "Scan now".
- A very large file count: the scan stays within the long-queue timeout or fails cleanly.
