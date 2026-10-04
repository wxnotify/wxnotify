---
paths:
  - 'app/Actions/**'
---

# Actions

## Activity causer: causedBy() is ignored; pin with CauserResolver::withCauser
AppServiceProvider's CauserResolver `resolveUsing` closure ignores the `causedBy()` subject: it returns `auth()->user()`, or the impersonator. A session-less request (the app token exchange, queued jobs) therefore logs a null causer unless the call is wrapped: `app(CauserResolver::class)->withCauser($user, fn () => activity()->...->log(...))`.
