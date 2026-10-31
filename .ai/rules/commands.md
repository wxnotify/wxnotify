---
paths:
  - 'app/Console/Commands/**'
---

# Commands

## alerts:dispatch-outlook-reports: UTC once-a-day report, send time capped at 22:45
The `outlook_report` rule category is clock-triggered: `delivery_time` is a UTC send time (default 14:30, picker capped at 22:45 so the 60 minute Day 1 wait ends before midnight UTC; the grace never rolls over midnight, deliberately), `report_mode` is every_day or only_when_forecast, chosen locations live in `alert_rule_location`. The command runs every 15 minutes (withoutOverlapping), fetches SPC once per run and only when a rule is due, isolates each rule (exception class logged, rule not stamped) and stamps `last_scheduled_run_on` (UTC date) in the same transaction as the dispatch. The report is paid-tier only: Free-owned rules are skipped (no fetch, no stamp). The rule's Message length decides: Brief sends the Brief text to every channel (no report built, no graphics); Full or unset sends the Full report to rich-content channels (email) and the Brief text to every other channel. The API leaves the category to the dashboard (writes refused, hidden from the options list) until the mobile app has an editor.
