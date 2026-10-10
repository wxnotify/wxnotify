---
paths:
  - 'app/Actions/**'
---

# Actions

## Activity causer: causedBy() is ignored; pin with CauserResolver::withCauser
AppServiceProvider's CauserResolver `resolveUsing` closure ignores the `causedBy()` subject: it returns `auth()->user()`, or the impersonator. A session-less request (the app token exchange, queued jobs) therefore logs a null causer unless the call is wrapped: `app(CauserResolver::class)->withCauser($user, fn () => activity()->...->log(...))`.

## Alert rule persistence lives in SaveAlertRule
`CreateAlertRule` and `EditAlertRule` (dashboard) and `AlertRuleController` (API) all persist through `App\Actions\Alerts\SaveAlertRule` (`attributes()` for the columns, `syncRelations()` for the pivots). A change to what a rule save does belongs in that action, not in a Filament page hook or a controller, and needs a case in `AlertRuleParityTest`.
