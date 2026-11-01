---
paths:
  - 'app/Actions/Storage/**'
---

# Storage

## Product Storage is a snapshot of the product files, with a live disk gauge
The Product Storage admin page (app/Filament/Admin/Pages/ProductStorage.php) counts product FILES only, never the database. An hourly, unique job on the `long` queue (ScanProductStorageJob -> ScanProductStorage) walks the NWWS and EMWIN roots and writes a product_storage_scans row plus bucket rows (source, catalog group, file kind); the stats and charts read the latest COMPLETE scan, they never walk the volumes. The disk gauge is the only live part (DiskGauge, disk_free_space/disk_total_space; one gauge when both roots share a device, two when not; DiskGauge::deviceOf() is public so tests can fake devices).
A volume that cannot be read is never recorded or shown as zero: the scan is marked failed and the gauge shows Unavailable. A scan `running` for over 20 minutes is treated as interrupted (it is never marked failed by itself); Scan now is refused only while one is genuinely running.
Catalog-group buckets keep the top 20 groups plus "Smaller groups". "Unmatched" means a file with no ingested_products row (on dev about 98k files, likely duplicates the pipeline skipped); rows with no catalog product are grouped by the WMO T1T2 letters. Symlinks are not followed.
