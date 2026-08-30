---
paths:
  - 'app/Models/**'
---

# Models

## spatie/activitylog: logOnlyDirty() alone logs nothing — call logAll() or logOnly() first
`LogOptions::defaults()->logOnlyDirty()->dontLogEmptyChanges()` (no `logAll()`/`logOnly()`) silently logs NOTHING — `$logAttributes` defaults to an empty array, and `logOnlyDirty()` only narrows an already-nonempty list. No error, no warning — the model just never produces activity rows. Always call `->logAll()` (full CRUD) or `->logOnly([...])` (restricted fields) before `->logOnlyDirty()`.

Also: the method is `dontLogEmptyChanges()`, not `dontSubmitEmptyLogs()` (easy to guess wrong). Correct namespaces: `Spatie\Activitylog\Models\Concerns\LogsActivity`, `Spatie\Activitylog\Support\LogOptions` (verified by reading vendor source, not the docs — the Traits\ and top-level namespace variants don't exist in this version).

Found while wiring AlertRule/Location for the Admin Tools audit log — both were completely silent until caught by a manual browser test.
