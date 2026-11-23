# Alert limits per tier, and the Outlook Report for Free: design

## Why

Nothing limits how many alert rules a subscriber can have. The tiers should differ here, and Free subscribers should get a taste of the Severe Weather Outlook Report, which is paid-only today. Self-hosters set their own numbers.

## Decisions (agreed in conversation, 2026-11-22)

- Alert-rule limits per tier, defaults **Free 3, Plus 5, Pro 10**. Pro is a number, not unlimited.
- Self-hosters change the three numbers in **Admin > App settings > Tier & threshold defaults**, with the other tier thresholds.
- Free can use the Severe Weather Outlook Report. It counts as one of the Free subscriber's alerts, and a Free subscriber may have **at most one** Outlook Report. Plus and Pro are unchanged (no separate Outlook cap).
- **Someone over their limit** (a Free user with 7 alerts, or a downgrade) keeps every alert, active and editable. They only cannot add another until they are under the limit. Nothing is deleted, paused or stopped.
- Paused (inactive) alerts count toward the limit, so pausing one cannot be used to make room.

## Goals

- Every way of creating an alert (dashboard, API, mobile app) is refused at the limit, enforced on the server.
- The dashboard and the API options tell the subscriber the limit and when they are at it, before they try.
- Free Outlook Reports are selectable, saved, previewed and **sent**.

## Anti-goals

- An existing alert must never be deleted, paused or stopped by this change or by a tier change.
- A Free subscriber must never get a second Outlook Report, by creating one or by switching an existing alert's category to Outlook.
- Hiding the button is a courtesy, not the control.

## Approach (chosen: model-level guard, as locations do)

`Location::creating` already enforces the location limit for every path. Alerts do the same.

- `TierThresholdSettings` gains `free_alerts`, `plus_alerts`, `pro_alerts` (migration in `database/settings`, defaults 3, 5, 10).
- `User::alertRuleLimit(): int` and `User::canCreateAlertRule(): bool` (count of the user's alert rules, active or not, under the limit), next to `locationLimit()` / `canCreateLocation()`. `User::canCreateOutlookReport(): bool`: true for Plus and Pro, and for Free only with no Outlook Report yet.
- `AlertRule` refuses, with a dedicated exception, a **create** at the limit, a create of a second Free Outlook Report, and an **update** that changes the category to Outlook for a Free subscriber who already has one. Editing an existing alert is never refused. `SaveAlertRule` and `SaveAlertRuleRequest` turn the exception into a validation message so the form and the API explain it.
- The "Available on paid plans" gate for Outlook goes: the category picker, `SaveAlertRule::attributes()`, the form preview widget, and the Free filter in `DispatchOutlookReports`. In their place the picker disables Outlook with a reason when a Free subscriber already has one.
- Dashboard: the My Alerts Create button is disabled with a tooltip at the limit and shows "N of M alerts" (as Locations does). API: the alert options payload (`BuildAlertRuleOptions`) gains the limit, count and can-create flags (and Outlook availability) so the mobile app can mirror them.
- App settings: an "Alerts" section with three counts, Plus >= Free and Pro >= Plus, validated like the other tier counts.

Ruled out: checking only in `SaveAlertRule` and the API request (other paths could bypass it), and hiding the button only.

## Left to the builder

Exact wording of messages and tooltips, the exception class name, whether the "N of M" counter is a subheading or a badge, and the test layout (follow the Location limit tests).

## Calls made without being asked (open to change)

- A downgraded subscriber's existing Outlook Reports start sending again (the Free skip in `DispatchOutlookReports` goes away). They are capped at one only for creating new ones.
- Existing alerts over the new limit on first deploy are untouched; no data migration.
- The mobile app repository is not changed here. It will see the new fields in the options payload when it is ready to use them.
