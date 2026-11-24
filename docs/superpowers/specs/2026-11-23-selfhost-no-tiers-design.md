# No tiers or limits when self-hosting: design

## Why

Tiers (Free, Plus, Pro) and their thresholds exist to meter a subscription product. A self-hosted install has no billing, so metering its own owner and family has no purpose, and it adds an admin tab, a per-user override and limit messages that mean nothing there.

## Decisions (agreed in conversation, 2026-11-23)

- When `WXNOTIFY_MODE=selfhost`, nobody has a tier: every account is unlimited and sees every feature. There is no opt-back-in (no optional thresholds).
- Cloud mode is unchanged in every respect.
- Built as **one switch in `User`** (approach A), not a new `Tier` case and not per-gate checks.

## Goals

- In self-host mode every limit is gone: saved locations, phone locations, alert rules, Traccar servers, Traccar devices per server, and the free no-cost channel slots. The Free-only features open up: custom subjects and templates, message styles, alert templates, Traccar servers, the Outlook Report cap, and the channel catalog's `min_tier`.
- No tier language in the self-hosted UI: no Tier override on users, no "Tier & threshold defaults" tab, no "specific tiers" announcement audience, no "N of M" or "upgrade" copy, no tier label in the app's `/me`.
- Switching an install between modes loses nothing: tier overrides and threshold settings stay in the database and are ignored while self-hosted.

## Anti-goals

- A cloud subscriber must never get anything they did not have before (cloud behaviour is bit-for-bit as today).
- Self-hosting must never lose or change stored tier data.
- Removing the tier UI must not remove the one setting that is not a tier limit (the lightning minimum radius).

## Approach (chosen)

`User::hasTiers(): bool` is false when `config('wxnotify.mode') === 'selfhost'`.

- `User::tier()` returns `Tier::Pro` when `! hasTiers()` (otherwise today's `tier_override ?? Free`). Every existing "is this Free?" gate opens with no further change: custom subjects and templates, message styles, the Outlook cap, alert templates, Traccar servers, channel `min_tier`.
- Every limit method returns `null` (unlimited) when `! hasTiers()`: `locationLimit()`, `phoneLocationLimit()`, `alertRuleLimit()`, `traccarServerLimit()`, `traccarDeviceLimitPerServer()` (the last two and `alertRuleLimit()` become `?int`), and the free no-cost channel check in `canAddChannel()`. The `can*` methods already treat null as unlimited (alerts and the Traccar callers are updated to).
- Anything that prints a limit ("You're using N of M alerts", "N of M" on the options payload, tooltips, limit notifications) shows no limit when null. The API options give `alert_limit: null` and `can_create_*: true`.
- Admin, hidden when `! hasTiers()`: the Tier override field, reason and table column on Users (and the create/edit handling), the "Tier & threshold defaults" tab on App settings, and the "specific tiers" announcement scope. The lightning minimum radius field moves to a visible section so it stays editable.
- `/me` reports `tier: pro` in self-host mode (the mobile app never shows "Free").
- `deploy/README.md` "Self-hosted mode and tiers" is rewritten: no tiers, no limits, everyone has every feature.

Ruled out: a new `Tier::Unlimited` case (every exhaustive `match` and stored value would learn it, for the same result); an `isSelfHosted()` check at each gate (about 20 files, easy to miss one).

## Left to the builder

The exact helper name and where it lives, how the limit-printing UI handles null, the new section name for the lightning radius, and the test layout (a self-host variant of the existing limit tests).

## Calls made without being asked (open to change)

- Self-host `/me` says `pro`, not a missing field, so the app keeps working unchanged.
- Existing tier overrides and threshold values are left in place, ignored, and come back if the install returns to cloud mode.
- The lightning minimum radius stays a (visible) setting, because it is a detection-accuracy floor, not a plan limit.
