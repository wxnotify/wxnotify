---
paths:
  - 'app/Actions/Alerts/**'
---

# Alerts

## Needs attention: an unverified channel is unconfirmed, never failing
`GetAlertsNeedingAttention` never lists an unverified channel as failing: the dispatcher logs its refusal of an unconfirmed channel as a `failure` row, so `failingChannels()` excludes channels with a null `verified_at` and the unverified row (which tells the subscriber to confirm it and that alerts will not be sent until then) is the only one shown.

## The outlook report is paid-only, enforced in SaveAlertRule
`SaveAlertRule` refuses to create or save an `outlook_report` rule for a Free owner with a ValidationException on `category` (`SaveAlertRule::PAID_ONLY_MESSAGE`); the dashboard also disables the category card and re-keys that error to `data.category` through `AlertRuleForm::saveForForm()`. An existing rule of a downgraded owner is kept (Active toggle and delete still work) but `DispatchOutlookReports` skips it. The API refuses the category on write regardless of tier.
