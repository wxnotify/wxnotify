---
paths:
  - app/Jobs/DeliverWeatherAlert.php
  - 'app/Jobs/**'
---

# App Jobs

## DeliverWeatherAlert is unique per rule+channel+product; the job re-checks DeliveryLog
`DeliverWeatherAlert` implements `ShouldBeUnique` with `uniqueId()` = `{rule}:{channel}:{product}` ONLY (never location/segment ids, never personal data) and `uniqueFor` 3600 s, because `MatchWeatherAlertRules::dispatchOrSuppress()`'s `DeliveryLog::exists()` guard cannot see an in-flight job: the DeliveryLog row is only written when the job runs, and a deliberate re-match (`BackfillOutlookRiskAreas` re-matches the `swody1` row when `ptsdy1` arrives, in the same second) dispatched a second delivery on every SPC Day 1 outlook. The lock (released after `handle()` or in `failed()`) covers a first job still queued or running; `handle()` also starts with the same existence guard (any status for that rule/channel/product) for a job finished or queued before deploy. Use `ShouldBeUnique`, not `ShouldBeUniqueUntilProcessing` (that releases the lock before the send and leaves a gap before the DeliveryLog write). Any new dispatch path for this job must not reuse the same rule/channel/product for a different purpose.

## Subscriber-sending jobs go on the `deliveries` priority queue
Every job that sends to a subscriber channel sets `$this->onQueue('deliveries')` in its constructor (DeliverWeatherAlert, DeliverScheduledAlert). The worker reads `deliveries,default`, so a delivery runs before queued processing jobs but never pre-empts the job in hand. Processing, housekeeping and admin jobs stay on `default`; `RecordQueueHeartbeat` must stay on `default` (it proves the main worker runs). A job relying on running after a delivery must use a delay, never queue order. Known gap: a long `default` job (ScanShapefileLayersJob, timeout 900) holds the only worker and blocks deliveries; `ScanShapefileLayersJob`'s 900 s exceeds `retry_after` 90, safe only while one worker reads `default`.
