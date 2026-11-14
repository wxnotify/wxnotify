# Account deletion: design

## Why

Subscribers have no way to delete their own account. Apple (and Google Play) require an app that lets people create an account to let them start deleting it from inside the app, and the mobile app signs in through the web dashboard, so the web flow is what the app links to. Beyond the store rule, subscribers simply have no way out today.

## Decision (approved in conversation, 2026-11-14)

Request, then delete after a grace period of 7 days (`wxnotify.account_deletion_days`, default 7).

Approaches considered: delete immediately (simplest, but a stolen session or a mis-click is unrecoverable, and social-login accounts have no password to confirm with); request then delete after a grace period (chosen); soft-delete/anonymise and keep rows (rejected: it is not what deleting an account means).

## Behaviour

- **Where:** a "Delete account" section at the bottom of the profile page (`#delete-account`, the anchor the mobile app links to). Cloud and self-hosted alike.
- **Request:** the subscriber types their account email to confirm (it works for social-login accounts, which have no known password). Requesting sets `users.deletion_requested_at`, signs the account out everywhere (all `sessions` rows and every Sanctum token: phones and API keys), and emails the address on file with the deletion date and how to cancel. It is logged as `deletion-requested`.
- **During the window:** alerts stop (the channel dispatcher refuses sends for an account pending deletion, the same refusal point as the tier check), the app cannot sign in again (the app-authorisation page and token exchange refuse), and the web dashboard still works so the subscriber can cancel. Every dashboard page shows a banner with the deletion date and a link to the cancel button on the profile page.
- **Cancel:** the profile section shows a "Cancel deletion" button; it clears `deletion_requested_at`. Alerts resume (rules and channels were never touched).
- **Purge:** `accounts:purge-deleted` runs daily and deletes every account whose window has passed. The user row delete cascades through the foreign keys; the purge also removes what does not cascade (Sanctum tokens, `sessions` rows, activity-log rows where the person is the causer or subject) and writes one anonymous `account-deleted` activity entry carrying only the user id.
- **Not allowed:** staff and owners (demote first), and while staff impersonate the subscriber. A staff member who is promoted during the window is skipped by the purge.

## Non-goals

A paid-plan block (billing is not live; add it where `canRequestDeletion()` is decided when it is), deleting the Chatwoot support contact, an admin-side "scheduled for deletion" column or cancel action, and the link from the mobile app (separate repo).

## Rulings

- Confirmation is typing the account email, not the password, because social-login accounts do not know theirs. The emailed notice and the 7-day cancel window are the protection against a hijacked session.
- Alerts are stopped at delivery (one refusal point), not by deactivating rules, so cancelling needs no restore step.
