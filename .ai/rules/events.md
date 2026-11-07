---
paths:
  - 'app/Events/**'
---

# Events

## Broadcast events never touch ingestion
`ProductIngested` goes on its own `broadcasts` queue (last in the worker's `--queue=deliveries,default,broadcasts` list, in compose.yaml and compose.dev-listen.yaml) with 2 s connect / 3 s total HTTP timeouts, so a hung Reverb can never delay matching or delivery. `broadcastWhen()` and `ProcessIngestedProduct::announce()` skip entirely when the default broadcaster is off (config arrives as PHP null, not the string 'null'). The payload is plain data computed once in the constructor; never raw product text. Failures are logged with the exception class only. `queue:prune-failed` is scheduled so failed broadcasts do not pile up.
