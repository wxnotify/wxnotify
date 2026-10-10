---
paths:
  - 'app/Actions/**'
---

# Actions

## Activity causer: causedBy() is ignored; pin with CauserResolver::withCauser
AppServiceProvider's CauserResolver `resolveUsing` closure ignores the `causedBy()` subject: it returns `auth()->user()`, or the impersonator. A session-less request (the app token exchange, queued jobs) therefore logs a null causer unless the call is wrapped: `app(CauserResolver::class)->withCauser($user, fn () => activity()->...->log(...))`.

## Alert rule persistence lives in SaveAlertRule
`CreateAlertRule` and `EditAlertRule` (dashboard) and `AlertRuleController` (API) all persist through `App\Actions\Alerts\SaveAlertRule` (`attributes()` for the columns, `syncRelations()` for the pivots). A change to what a rule save does belongs in that action, not in a Filament page hook or a controller, and needs a case in `AlertRuleParityTest`.

## A channel is created and sent its first code only through BeginChannelConnection
`App\Actions\Channels\BeginChannelConnection` stores the channel unverified, sends the confirmation code and removes a channel it just created when the code cannot be sent. The dashboard's `ConnectChannel` wizard and `POST /api/v1/channels` both call it; a change to what connecting a channel does belongs there, not in the page or the controller. It does not pin the activity causer (the wizard has a session, and impersonation attributes to the impersonator): the API controller wraps the call in `CauserResolver::withCauser`.
