---
paths:
  - 'app/Console/Commands/**'
---

# Commands

## alerts:dispatch-outlook-reports: once-a-day report at the owner's LOCAL send time, capped at 22:45
The `outlook_report` rule category is clock-triggered: `delivery_time` is the owner's LOCAL send time (default 14:30; timezone = `Timezones::forRule`, i.e. the owner's profile timezone, UTC when none; same local hour year-round across DST; picker capped at 22:45 local so the 60 minute Day 1 wait ends before the owner's local midnight; the grace never rolls over midnight, deliberately; `AlertRule::booted()` re-arms a rule moved ahead today in the same local timezone, never a UTC special case). The 60 minute grace and `Day1OutlookFreshness` compare instants (the send time is converted to UTC before choosing the expected SPC cycle), `report_mode` is every_day or only_when_forecast, chosen locations live in `alert_rule_location`. The command runs every 15 minutes (withoutOverlapping), fetches SPC once per run and only when a rule is due, isolates each rule (exception class logged, rule not stamped) and stamps `last_scheduled_run_on` (the owner's local date; changing the profile timezone can skip or repeat one day) in the same transaction as the dispatch. The report is open to every tier: a Free owner's due Outlook Report is sent like any other (Free is limited to one such rule, enforced at save). The rule's Message length decides: Brief sends the Brief text to every channel (no report built, no graphics); Full or unset sends the Full report to rich-content channels (email) and the Brief text to every other channel. The API leaves the category to the dashboard (writes refused, hidden from the options list) until the mobile app has an editor.
