# Product Storage Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A staff-only Admin page, "Product Storage", that shows how full the disk holding the NWS product files is and how the product-file space splits by source, catalog group and file kind.

**Architecture:** An hourly scheduled job (long queue) walks the two product roots, classifies each file (source by root, file kind by extension, catalog group by matching `ingested_products.file_path`), and stores one `product_storage_scans` row plus `product_storage_buckets` rows. The page reads the latest successful scan for the charts and stats, and reads the disk gauge live with `disk_free_space()` / `disk_total_space()`.

**Tech Stack:** Laravel 13, PHP 8.4, Filament v5 (Page + ChartWidget), Pest, Postgres.

**Spec:** `docs/superpowers/specs/2026-11-01-product-storage-page-design.md`

## Global Constraints

- Files only: no database size anywhere. The page never deletes or cleans up files.
- Thresholds: normal below 80%, amber from 80%, red from 90% (constants, not a setting).
- A stale snapshot is one older than 3 hours, or a latest scan that failed.
- Scan on connection `database_long`, queue `long` (only when `queue.default` is `database`), `$timeout = 900`, `$tries = 1`, as `ScanShapefileLayersJob` does. Never on `default` or `deliveries`.
- Product roots: `config('services.pipeline.products_path')` (NWWS) and `config('services.pipeline.emwin_products_path')` (EMWIN). EMWIN-HTTP is anything under `<emwin root>/http/`.
- `ingested_products.file_path`: NWWS = path relative to the NWWS root; EMWIN (both feeds) = `emwin/` + path relative to the EMWIN root. Never select the `text` column.
- Each of the three bucket dimensions sums to the same product-bytes total.
- Snapshot retention: `config('wxnotify.storage.snapshot_retention_days')`, env `STORAGE_SNAPSHOT_RETENTION_DAYS`, default 90.
- Tests never touch the real volumes: they build temp directories and point the two `services.pipeline.*_path` configs at them. Commit trailer: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Follow `.ai/rules` (read `index.md` first); run `vendor/bin/pint --dirty --format agent` before each commit; run tests with `php artisan test --compact <file>` inside the app container (`docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && ...'`).

## Review Focus

- A product root that is missing or unreadable: gauge and source show "unavailable", never 0 or 0%. (Task 2, Task 6)
- A symlink inside a product root is not followed; a file that vanishes mid-walk is skipped, not fatal. (Task 3)
- Two scans never overlap; a scan that throws is recorded `failed` and the page keeps the last `complete` one. (Task 4, Task 5)
- Source, catalog-group and file-kind buckets each sum to the same total; a file with no row is "Unmatched"; a row with neither product nor `ttaaii` is "Unclassified". (Task 4)
- Fresh install (no scan yet) and stale/failed scan states render a clear message, not an error. (Task 6)

---

### Task 1: Snapshot tables, models and retention config

**Files:**
- Create (via `php artisan make:model ... -mf --no-interaction`, then edit): `app/Models/ProductStorageScan.php`, `app/Models/ProductStorageBucket.php`, their migrations and factories (`database/factories/ProductStorageScanFactory.php`, `ProductStorageBucketFactory.php`)
- Create: `app/Enums/StorageDimension.php`
- Modify: `config/wxnotify.php` (add `'storage' => ['snapshot_retention_days' => (int) env('STORAGE_SNAPSHOT_RETENTION_DAYS', 90)]`)
- Test: `tests/Feature/ProductStorageScanModelTest.php`

**Interfaces:**
- Produces:
  - `enum StorageDimension: string { case Source = 'source'; case CatalogGroup = 'catalog_group'; case FileKind = 'file_kind'; }`
  - `product_storage_scans`: `id`, `status` string (`running|complete|failed`), `started_at`, `finished_at` nullable, `error` text nullable, `nwws_bytes`, `emwin_byteblaster_bytes`, `emwin_http_bytes` (bigint, default 0), `file_count` unsigned bigint default 0, `oldest_file_at` timestamp nullable, `disks` json nullable (list of `{label: string, product_bytes: int, used_bytes: int, total_bytes: int}` as measured at scan time), timestamps. Index on `(status, finished_at)`.
  - `product_storage_buckets`: `id`, `product_storage_scan_id` FK cascade on delete, `dimension` string, `label` string, `bytes` bigint, `file_count` unsigned bigint; index on `(product_storage_scan_id, dimension)`.
  - `ProductStorageScan`: constants `STATUS_RUNNING|STATUS_COMPLETE|STATUS_FAILED`; casts (`started_at`, `finished_at`, `oldest_file_at` datetime, `disks` array); `buckets(): HasMany`; `static latestComplete(): ?self` (newest by `finished_at` with status complete); `static latest(): ?self` (newest by `id`); `totalBytes(): int` (sum of the three source columns); `bucketsFor(StorageDimension $d): Collection` ordered by `bytes` desc; scopes `complete()`. Factory states `complete()`, `failed()`, `running()`.
  - `ProductStorageBucket`: fillable fields above, `dimension` cast to `StorageDimension`.

- [ ] **Step 1: Write the failing test** `ProductStorageScanModelTest`: `latestComplete()` returns the newest complete scan and ignores a newer `failed` or `running` one; `latest()` returns the newest of any status; `totalBytes()` sums the three columns; deleting a scan deletes its buckets; `bucketsFor(StorageDimension::Source)` returns only that dimension, largest first.
- [ ] **Step 2: Run it, expect FAIL** (`php artisan test --compact tests/Feature/ProductStorageScanModelTest.php`).
- [ ] **Step 3: Implement** the migrations, models, enum, factories and config key as specified above.
- [ ] **Step 4: Run it, expect PASS**; run `php artisan migrate --no-interaction` in the container once.
- [ ] **Step 5: Commit** `Storage: snapshot tables for the product storage scan`.

---

### Task 2: Live disk gauge reading

**Files:**
- Create: `app/Support/ProductStorage/DiskReading.php`, `app/Support/ProductStorage/DiskGauge.php`
- Test: `tests/Feature/DiskGaugeTest.php`

**Interfaces:**
- Produces:
  - `final readonly class DiskReading { public function __construct(public string $label, public string $path, public ?int $usedBytes, public ?int $totalBytes) }` with `isAvailable(): bool`, `percent(): ?float` (one decimal, null when unavailable), `band(): string` returning `'normal'` below 80, `'warning'` from 80 up to below 90, `'critical'` from 90, `'unavailable'` when not available.
  - `class DiskGauge { public function readings(): array }` returning `list<DiskReading>`: reads the two roots' paths from config; if both resolve and share a filesystem (`stat($path)['dev']` equal) returns ONE reading labelled "Product disk"; if they differ returns TWO ("NWWS disk", "EMWIN disk"); a root that does not exist or whose `disk_total_space()` returns false gives an unavailable reading for it (used/total null). If NWWS is unavailable and EMWIN is available, return a reading for each (the unavailable one included) — never merge.
  - `DiskGauge::paths(): array{nwws: string, emwin: string}` returning the two configured roots.
- Consumes: `config('services.pipeline.products_path')`, `config('services.pipeline.emwin_products_path')`.

- [ ] **Step 1: Write the failing tests:** `DiskReading::band()` at 79.9 / 80 / 89.9 / 90 / 100 and unavailable; `percent()` rounds to one decimal; two temp dirs on the same filesystem give one reading with `usedBytes + free = total` shaped values (assert `used > 0`, `total >= used`); a missing path gives `isAvailable()` false and `percent()` null, and `readings()` has two entries when only one root exists.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** `DiskReading` and `DiskGauge` (used = total − `disk_free_space()`; suppress warnings from the PHP disk functions and treat `false` as unavailable).
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit** `Storage: live disk gauge reading with 80/90 bands`.

---

### Task 3: Product file walker and file-kind classification

**Files:**
- Create: `app/Support/ProductStorage/ProductFile.php`, `app/Support/ProductStorage/ProductFileWalker.php`, `app/Enums/FileKind.php`
- Test: `tests/Feature/ProductFileWalkerTest.php`

**Interfaces:**
- Produces:
  - `enum FileKind: string { case Text = 'text'; case Image = 'image'; case Other = 'other'; }` with `label()` ("Text products", "Images", "Other") and `static fromFileName(string $name): self`: case-insensitive extension `txt` → Text; a name whose extension is purely digits after `txt` (e.g. `a.txt.12`) → Text; `gif|jpg|jpeg|png` → Image; everything else → Other.
  - `final readonly class ProductFile { public function __construct(public string $source, public string $relativePath, public int $bytes, public int $modifiedAt) }` where `source` is `'nwws' | 'emwin_byteblaster' | 'emwin_http'` and `relativePath` is relative to that source's ROOT (for HTTP files this is relative to the EMWIN root, so it starts with `http/`). Method `filePath(): string` returning the `ingested_products.file_path` key (NWWS: `relativePath`; EMWIN: `'emwin/'.relativePath`).
  - `class ProductFileWalker { public function walk(): Generator }` yielding `ProductFile` for every regular file under both roots. Uses `RecursiveDirectoryIterator` with `SKIP_DOTS` and no symlink following (`isLink()` entries are skipped, both files and directories). A file that cannot be stat-ed is skipped. A missing root yields nothing for that root and is reported through `unavailableRoots(): list<string>` (values `'nwws'`, `'emwin'`) after the walk completes.
- Consumes: `DiskGauge::paths()` from Task 2.

- [ ] **Step 1: Write the failing tests** (temp dirs, config pointed at them): `FileKind::fromFileName` for `a.TXT`, `a.txt.12`, `a.GIF`, `a.jpg`, `a.png`, `a.bin`, `noext`; the walker yields the right `source` for a NWWS file, an EMWIN root file and a file under `http/`; `filePath()` for each; a symlinked file and a symlinked directory are not yielded; a missing root yields nothing for it and appears in `unavailableRoots()` while the other root still walks; bytes and mtime match `filesize`/`filemtime`.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** the enum, DTO and walker.
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: On the dev volumes** (`/products`, `/emwin-products` in the container) time a full walk with a throwaway tinker call and note the seconds in the commit message; if it exceeds ~300 s, report it as a concern instead of silently switching to a shell `find`.
- [ ] **Step 6: Commit** `Storage: walk the product volumes without following symlinks`.

---

### Task 4: ScanProductStorage action

**Files:**
- Create: `app/Actions/Storage/ScanProductStorage.php`, `app/Support/ProductStorage/CatalogGroupLabel.php`
- Test: `tests/Feature/ScanProductStorageTest.php`

**Interfaces:**
- Consumes: `ProductFileWalker`, `ProductFile`, `FileKind`, `DiskGauge`, `ProductStorageScan`, `ProductStorageBucket`, `StorageDimension` (Tasks 1–3); `IngestedProduct` (`file_path`, `product_id`, `ttaaii`), `Product` (`family`, cast to `ProductFamily`).
- Produces:
  - `class CatalogGroupLabel { public static function forRow(?ProductFamily $family, ?string $ttaaii): string }`: a family gives `ProductFamily::label()`; no family but a `ttaaii` of at least two letters gives `"Other: <T1 name> (<first two letters uppercased>)"` using a small map of WMO T1 letters (A analyses, C climatic, F forecasts, N notices, O oceanographic, S surface observations, T satellite, U upper-air, W warnings; any other letter gives `"WMO <pair>"` instead of `"Other: ..."`); neither gives `"Unclassified"`. The constant `CatalogGroupLabel::UNMATCHED = 'Unmatched'` is for files with no row; `CatalogGroupLabel::SMALLER = 'Smaller groups'` is the remainder bucket.
  - `class ScanProductStorage { public function __invoke(): ProductStorageScan }`. Behaviour: creates a scan `running`; refuses to start (returns the existing running scan untouched) if another `running` scan started less than 20 minutes ago; walks files; every 2,000 files does ONE `IngestedProduct::whereIn('file_path', $keys)->get(['file_path','product_id','ttaaii'])` and one preloaded `product_id => family` map (the `products` table is small: load once); accumulates per-source, per-file-kind and per-catalog-group bytes/counts and the minimum mtime; keeps the top 20 catalog groups by bytes and folds the rest into `CatalogGroupLabel::SMALLER`; writes the three dimensions' buckets; sets the three source byte columns, `file_count`, `oldest_file_at`, and `disks` (for each `DiskGauge` reading: label, `used_bytes`, `total_bytes`, and `product_bytes` = bytes of the sources whose root is on that disk; with one shared disk that is all product bytes); marks `complete` with `finished_at`. A root reported unavailable by the walker marks the scan `failed` with `error` "Product volume unavailable: nwws" (naming it) rather than completing with zeros. Any `Throwable` marks the scan `failed` with the exception class and message, and re-throws nothing (the job must not retry). Prunes scans (and, by cascade, their buckets) older than the retention days, only after a scan completes.

- [ ] **Step 1: Write the failing tests** (temp dirs, factories for `IngestedProduct` with `file_path` set, `Product` with a family): one NWWS text file matched to a product with family Warning → catalog bucket "Warnings"; an EMWIN file matched to a row with no product and `ttaaii` `FZUS51` → `"Other: forecasts (FZ)"`; a matched row with neither → `"Unclassified"`; a `.gif` with no row → file-kind "Images" and catalog "Unmatched"; an `http/` file → source `emwin_http`; **each dimension's buckets sum to the scan's total bytes**; `file_count` and `oldest_file_at` correct; more than 20 distinct groups fold into "Smaller groups" and still sum; a missing EMWIN root → scan `failed`, error names `emwin`, and `latestComplete()` still returns an earlier complete scan; a second invocation while a fresh `running` scan exists returns that scan and creates no new row; a scan older than retention is pruned after a new complete one (with its buckets).
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** `CatalogGroupLabel` and `ScanProductStorage` as specified.
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: On the dev data** run the action once from tinker in the container; record the duration, peak memory (`memory_get_peak_usage`) and the resulting totals in the commit message, and report any concern (for example peak memory above 150 MB).
- [ ] **Step 6: Commit** `Storage: scan the product volumes into a snapshot`.

---

### Task 5: Scan job, hourly schedule and command

**Files:**
- Create: `app/Jobs/ScanProductStorageJob.php`, `app/Console/Commands/ScanProductStorageCommand.php` (signature `storage:scan-products`)
- Modify: `bootstrap/app.php` (add `$schedule->command('storage:scan-products')->hourly()->withoutOverlapping(60);`)
- Test: `tests/Feature/ScanProductStorageJobTest.php`

**Interfaces:**
- Consumes: `ScanProductStorage` (Task 4).
- Produces:
  - `class ScanProductStorageJob implements ShouldQueue, ShouldBeUnique` with `$timeout = 900`, `$tries = 1`, `$uniqueFor = 1200`, `uniqueId(): 'product-storage-scan'`, the same constructor routing to `database_long`/`long` as `ScanShapefileLayersJob` when `queue.default` is `database`, `handle(ScanProductStorage $scan): void` calling `$scan()`, and `failed(?Throwable)` logging an error.
  - The command dispatches `ScanProductStorageJob` and prints "Queued a product storage scan.".

- [ ] **Step 1: Write the failing tests:** with `Queue::fake()`, `storage:scan-products` pushes exactly one `ScanProductStorageJob`; with `queue.default` = `database` the job's connection is `database_long` and queue `long`; with `sync` they stay default; the schedule contains `storage:scan-products` hourly (assert via `Schedule` events list); running the job's `handle` with temp dirs creates a complete scan.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** the job, command and schedule line.
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit** `Storage: hourly product storage scan on the long queue`.

---

### Task 6: Product Storage page, gauge and stat tiles

**Files:**
- Create: `app/Filament/Admin/Pages/ProductStorage.php`, `resources/views/filament/admin/pages/product-storage.blade.php`, `app/Filament/Admin/Widgets/ProductStorageStats.php`
- Test: `tests/Feature/ProductStoragePageTest.php`

**Interfaces:**
- Consumes: `DiskGauge`, `DiskReading` (Task 2), `ProductStorageScan` (Task 1), `ScanProductStorageJob` (Task 5).
- Produces:
  - `ProductStorage extends Filament\Pages\Page`: navigation group `Operations`, label "Product Storage", icon `Heroicon::OutlinedCircleStack`; `canAccess()` returns `auth()->user()?->is_staff === true`; header action `scanNow` ("Scan now") that dispatches `ScanProductStorageJob` and sends a notification "Scan queued"; the action is disabled (with the tooltip "A scan is already running") while `ProductStorageScan::latest()` is `running` and started under 20 minutes ago.
  - The view shows, from `DiskGauge::readings()`, one block per reading: label, a bar filled to `percent()` coloured by `band()` (inline styles; the admin panel has no compiled app utility classes), "X GB used of Y GB (Z% full)", and for an available reading with a latest complete scan a line "Product files: N GB · Everything else: M GB" (everything else = used − that disk's `product_bytes` from `scan->disks` matched by label, never below 0). An unavailable reading shows "Unavailable: <path> could not be read" and no bar.
  - A status line: no scan yet → "No scan has run yet. Use Scan now."; latest scan failed → the error text plus "Showing the last successful scan from <time>" when one exists; latest complete older than 3 hours → "Snapshot is stale: last scan <relative time>"; otherwise "As of <time>". The line uses the staff user's timezone like other admin pages.
  - `ProductStorageStats extends StatsOverview` with tiles from `ProductStorageScan::latestComplete()`: Product files (formatted total), Files (count), Oldest file (age from `oldest_file_at`, "—" when null), Last scan (relative time). With no complete scan every tile shows "—". `getHeaderWidgets()` on the page returns `[ProductStorageStats::class]`.
  - Byte formatting helper `App\Support\ProductStorage\Bytes::format(int $bytes): string` (binary units, one decimal, "0 B").

- [ ] **Step 1: Write the failing tests** (`Filament::setCurrentPanel('admin')`, temp dirs for the roots, `Queue::fake()`): non-staff gets 403; staff sees "No scan has run yet"; with a complete factory scan sees "As of" and the formatted total; a failed latest scan shows the error and the last good time; a 4-hour-old scan shows "stale"; a missing product root shows "Unavailable" and no percentage; `callAction('scanNow')` pushes `ScanProductStorageJob`; the action is disabled while a fresh `running` scan exists; the gauge bar's colour is amber at 80% and red at 90% (assert the inline style colour via a stubbed `DiskGauge` bound in the container); `Bytes::format` cases.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** the page, view, stats widget and `Bytes` helper.
- [ ] **Step 4: Run, expect PASS.** Then open the page in the browser pane and screenshot it in light and dark mode (spot check only).
- [ ] **Step 5: Commit** `Storage: Product Storage admin page with the disk gauge`.

---

### Task 7: Charts and project rules

**Files:**
- Create: `app/Filament/Admin/Widgets/StorageBySourceChart.php`, `StorageByCatalogGroupChart.php`, `StorageByFileKindChart.php`, `StorageGrowthChart.php`
- Modify: `app/Filament/Admin/Pages/ProductStorage.php` (`getFooterWidgets()` returns the four charts; `getFooterWidgetsColumns()` returns 2)
- Test: `tests/Feature/ProductStorageChartsTest.php`
- Rules: record via the `record-rule` MCP tool (not by hand): glob `app/Actions/Storage/**` and `app/Filament/Admin/Pages/ProductStorage.php`, a note on the scan model (snapshots, 20-minute running guard, top-20 groups, files-only, gauge live vs charts snapshot, never zeros for an unavailable volume).

**Interfaces:**
- Consumes: `ProductStorageScan::latestComplete()`, `bucketsFor()`, `StorageDimension` (Task 1), `Bytes` (Task 6).
- Produces: four `ChartWidget`s following `AlertRulesByCategoryChart`. Source, catalog-group and file-kind charts are doughnut/bar charts of the latest complete scan's bucket bytes converted to GB (two decimals), with the tooltip/label showing the formatted size; each shows an empty dataset (no error) when there is no complete scan. `StorageGrowthChart` is a line chart over the last 30 days of complete scans (one point per scan, newest last): datasets NWWS, EMWIN Byteblaster, EMWIN HTTP in GB, plus disk % used as a second dataset on a secondary axis taken from the first `disks` entry's `used_bytes/total_bytes`.

- [ ] **Step 1: Write the failing tests** (`livewire(Widget::class)`): each chart's labels and data equal the factory buckets' labels and GB values, largest first; with no complete scan each renders with empty data; the growth chart returns one point per complete scan in order and ignores failed scans; the page renders all four widgets (`livewire(ProductStorage::class)->assertSeeLivewire(...)` for each).
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** the widgets and register them on the page.
- [ ] **Step 4: Run, expect PASS**; then run the four storage test files plus `PipelineControlTest.php` together.
- [ ] **Step 5: Record the rule** with `record-rule` and verify `.ai/rules` shows the new entry; run `vendor/bin/pint --dirty --format agent`.
- [ ] **Step 6: Commit** `Storage: charts for source, catalog group, file kind and growth`.
