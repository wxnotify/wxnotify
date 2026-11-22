---
paths:
  - 'app/Actions/Account/**'
---

# Account

## Account deletion is request, 7-day grace, then purge; alerts stop at the channel dispatcher
A subscriber requests deletion from the profile page (typing their account email; staff and owners cannot, and it is disabled while impersonating). RequestAccountDeletion sets users.deletion_requested_at, deletes every Sanctum token and the user's sessions rows and emails the address on file; the page action then logs the browser out (a session row deleted mid-request would otherwise be rewritten when the request ends). Nothing else is touched, so CancelAccountDeletion is just clearing the column. While pending, ChannelDispatcher::refusal() refuses every send (PENDING_DELETION_REFUSAL, the same place as the tier check), the app-authorisation page and token exchange refuse, and a dashboard banner links to the cancel button (#delete-account). The grace period is wxnotify.account_deletion_days (default 7). accounts:purge-deleted runs daily and calls PurgeAccount, which removes what the foreign keys do not cascade (tokens, sessions, activity-log rows naming the user) and writes one anonymous `account-deleted` activity entry with only the user id; it skips staff and owners promoted during the window. A new table with a user_id foreign key cascades for free, but anything keyed by a morph or a plain id (like tokens, sessions, activity rows) must be added to PurgeAccount.

## Every sign-up door goes through SignUpGate
Every way to create an account (the Register page, Google registration, any new provider) calls SignUpGate.
The sign-up policy (Open, Invite only, Closed) is a runtime setting read by the gate, never a boot-time `registration()` toggle on the panel; the register route stays registered and the page refuses.
An invite code is spent only inside SignUpGate::admit()'s transaction. The closure passed to admit() creates the user only, so events and mail fire after it returns.
