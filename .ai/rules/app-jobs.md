---
paths:
  - app/Jobs/DeliverWeatherAlert.php
  - 'app/Jobs/**'
  - app/Jobs/DeliverScheduledAlert.php
---

# App Jobs

## DeliverWeatherAlert is unique per rule+channel+product; the job re-checks DeliveryLog
`DeliverWeatherAlert` implements `ShouldBeUnique` with `uniqueId()` = `{rule}:{channel}:{product}` ONLY (never location/segment ids, never personal data) and `uniqueFor` 3600 s, because `MatchWeatherAlertRules::dispatchOrSuppress()`'s `DeliveryLog::exists()` guard cannot see an in-flight job: the DeliveryLog row is only written when the job runs, and a deliberate re-match (`BackfillOutlookRiskAreas` re-matches the `swody1` row when `ptsdy1` arrives, in the same second) dispatched a second delivery on every SPC Day 1 outlook. The lock (released after `handle()` or in `failed()`) covers a first job still queued or running; `handle()` also starts with the same existence guard (any status for that rule/channel/product) for a job finished or queued before deploy. Use `ShouldBeUnique`, not `ShouldBeUniqueUntilProcessing` (that releases the lock before the send and leaves a gap before the DeliveryLog write). Any new dispatch path for this job must not reuse the same rule/channel/product for a different purpose.

## Subscriber-sending jobs go on the `deliveries` priority queue
Every job that sends to a subscriber channel sets `$this->onQueue('deliveries')` in its constructor (DeliverWeatherAlert, DeliverScheduledAlert). The worker reads `deliveries,default`, so a delivery runs before queued processing jobs but never pre-empts the job in hand. Processing, housekeeping and admin jobs stay on `default`; `RecordQueueHeartbeat` must stay on `default` (it proves the main worker runs). A job relying on running after a delivery must use a delay, never queue order. Long jobs (ScanShapefileLayersJob) do not belong on `default`: see the `long` queue rule.

## Long jobs run on the `long` queue / `database_long` connection
ScanShapefileLayersJob (timeout 900) goes to connection `database_long`, queue `long` (only when `queue.default` is `database`; sync in tests keeps the default), read only by the `queue-long` worker (`queue:work database_long --queue=long --timeout=900`, grace 930 s). `retry_after` is per connection, so `database_long` has 960 (DB_QUEUE_LONG_RETRY_AFTER) above the job timeout. Any new job that can run longer than ~50 s goes there, never on `default`/`deliveries`, or it blocks alert delivery. `queue-long` must be started separately (`docker compose --profile server-c up -d queue-long`); nothing monitors it, and it is also stopped by `queue:restart` after the running scan finishes. The `queue-long` worker also runs ScanProductStorageJob and must mount nwws-products and emwin-products read-only; any other deployment's long-queue worker needs the same mounts.

## An outlook report job honours the rule's Brief setting
For an `outlook_report` rule `DeliverScheduledAlert` uses `$this->title` (the prebuilt circles subject, not RenderAlertSubject) and puts the report in `$meta['outlook_report']` only for rich-content channels. A rule whose Message length is Brief ignores a report already on the queued job and fetches no graphics (Brief means the short text everywhere). Graphics are fetched in `handle()` (one pool, failures cached), only for days the payload marks available, and their bytes never go on the queue. The job does not re-check the owner's tier: a job queued just before a downgrade still sends once.
