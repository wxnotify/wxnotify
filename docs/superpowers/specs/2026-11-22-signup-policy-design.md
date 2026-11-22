# Sign-up policy and invite codes: design

## Why

Today anyone who can reach the dashboard can create an account, on the hosted service and on every self-hosted install. Owners need to control who joins: a self-hoster running WxNotify for family and friends, or the hosted service during a limited rollout, should be able to close sign-ups, or open them only to people they have invited. Open sign-up stays the default, so nothing changes for anyone until an owner chooses otherwise.

## Decisions (agreed in conversation, 2026-11-22)

- One runtime setting, **sign-up policy**, with three values: **Open** (default, today's behaviour), **Invite only** and **Closed**. It applies to cloud and self-hosted alike and changes without a deploy.
- Invite codes are managed on a new admin page. Most invites go to individuals (single-use codes); an occasional code is shared (several uses).
- A code may optionally be tied to one email address. The field is blank by default.
- The rule lives in one class that every sign-up door calls.

## Goals

- An owner can switch the policy from the admin **App settings** page, and it takes effect on the next request.
- **Closed:** nobody can create their own account, by email or by Google. Admins still create users under **Admin > Users > Create**.
- **Invite only:** sign-up needs a valid code, by email or by Google. Codes are created, listed and revoked on an admin page.
- **Open:** exactly as today, including the existing Turnstile rules.
- Staff can see which code each account joined with.

## Non-goals

Emailing invites from WxNotify (the owner sends them), a "request an invite" form or waitlist, email-domain allow-lists, per-code tiers or other perks, CSV import, any change to the mobile app repository, and sign-in providers other than Google (there is only Google today; the gate is written so a new provider calls the same class).

## Anti-goals (a failure even if it technically works)

- Closing or restricting sign-up must never stop an existing user signing in, by password or by Google, or stop an admin creating users.
- A single-use code must never create two accounts, however close together two people submit it.
- The rule must be enforced on the server at every door. Hiding links is a courtesy, not the control.

## Constraints

- Laravel 13, Filament v5, spatie/laravel-settings for runtime settings (the admin App settings page already edits them), Pest tests, Pint. Follow `.ai/rules` for the paths touched (auth, filament, models, actions).
- Sign-up doors today: the dashboard `Register` page (`app/Filament/Dashboard/Pages/Auth/Register.php`, with Turnstile) and the Google callback (`FilamentSocialitePlugin->registration()->createUserUsing(...)` in `app/Providers/Filament/DashboardPanelProvider.php`). The plugin's `registration()` accepts a closure evaluated per request, so the gate can decide at request time. The mobile app signs in through the web dashboard, so it follows whatever the web does.
- Filament registers the register route at boot, before migrations exist on a fresh install, so the setting must not decide whether the route exists. The route stays; the page refuses when sign-ups are closed.

## Approaches considered

- **A. One gate class called from every door (chosen).** `SignUpGate` holds the policy, code validity, email lock and redemption. The Register page and the Google callback both call it. One rule, one test file.
- **B. Route middleware plus separate Google handling.** Less code at first, but the invite and email-lock logic would exist in two places that can drift apart.
- **Ruled out: turning Filament's `->registration()` on or off at boot from the setting.** It reads the setting while the app boots, which breaks on a fresh install and needs a cache clear after every change.

## Design

### The setting

A new `SignUpSettings` class (group `sign_up`) with one property, `policy`, backed by a `SignUpPolicy` string enum: `Open`, `InviteOnly`, `Closed`. A settings migration in `database/settings` sets the default to `open`, so existing installs behave exactly as before. The admin **App settings** page gets a **Sign-ups** tab with a select and a help line per value. When the value is Invite only the tab links to the invite-codes page.

### The gate

`App\Actions\Account\SignUpGate` (the builder may place it elsewhere under `app/Actions` if the actions rules say so) with two operations:

- **Check** (read-only): given the email and an optional code, say whether sign-up is allowed now, and if not, a reason the UI can show. Used for form validation and for hiding links.
- **Admit** (one transaction): re-check, create the user, and spend the code. It locks the code row (`lockForUpdate`), re-validates, creates the user through a closure the caller passes in, increments the code's `used_count`, and stores the code on `users.invite_code_id`. If anything fails, nothing is spent. This is what makes a single-use code safe under concurrent submits.

Rules, in order:
1. **Open:** allowed; any code is ignored.
2. **Closed:** refused.
3. **Invite only:** the code must exist (matched after normalising: trimmed, upper-cased, dashes and spaces removed), not be revoked, not be expired, have uses left, and, if it has an email, match the sign-up email (compared case-insensitively). An unknown, expired, revoked or spent code all give the same user-facing message, "That invite code isn't valid", so the form doesn't reveal which codes exist.

### The doors

- **Register page:** when Closed, mount redirects to the login page with the message "Sign-ups are closed. Ask the administrator for an account." When Invite only, the form gets a required **Invite code** field, filled from `?code=` on the URL and validated through the gate's check, with the email lock enforced against the email field. Submit calls Admit. Turnstile still applies exactly as today.
- **Google:** the plugin's `registration()` closure asks the gate, using the signing-in Google email and a *pending invite* in the session. The closure runs only when no matching user exists, so existing users still sign in. The pending invite is set when someone opens a valid invite link or submits a valid code on the Register page, and is cleared when it is spent. `createUserUsing` calls Admit. A refusal shows the plugin's existing "registration not enabled" login error; the builder should check that its text reads sensibly for both Closed and Invite only, and override it if not.
- **Links:** the login page's "Register" link and the Register link in `resources/views/welcome.blade.php` are hidden when Closed. Under Invite only they stay, because that is where the code goes.
- **Admin > Users > Create:** unchanged and unrestricted. It already creates verified accounts.
- **Mobile app:** no change. It opens the web sign-up, so it shows these same pages and messages.

### Invite codes

New `invite_codes` table: `code` (normalised, unique), `note` (nullable), `max_uses` (nullable, null means unlimited), `used_count` (default 0), `expires_at` (nullable), `email` (nullable, stored lower-case), `revoked_at` (nullable), `created_by` (staff user, nullable on delete), timestamps. New nullable `users.invite_code_id` foreign key, null on delete. Generated codes are 8 characters from an unambiguous alphabet (no `0/O/1/I/L`), shown as `ABCD-EFGH`. Custom codes use letters, digits and dashes, at least 4 characters after normalising.

Status is derived: **Revoked** if `revoked_at` is set, else **Expired**, else **Used up** if `used_count` has reached `max_uses`, else **Active**.

### The admin page

A Filament resource, **Invite codes**, in the same navigation group as Users and with the same authorisation as the Users resource. Table: code, note, status, uses (`2 / 5` or `0 / unlimited`), email, expiry, created by, created. Filter by status. Row actions: **Copy invite link** (`<APP_URL>/dashboard/register?code=ABCD-EFGH`), **Revoke** and **Restore**, and **Delete** only while unused (a used code is revoked, never deleted, so history stays). Header actions:
- **Generate invites:** a count (default 1, up to 50), an optional note, an optional expiry; makes that many single-use codes. When the count is 1 an optional email field appears.
- **New shared code:** optional custom code (blank means generate one), use limit, expiry, note.

**Admin > Users** shows a "Joined with" column and the code in the user's view, so staff can see how each account arrived.

### Messages and abuse

Code lookups sit behind the Register form's existing submit rate limit and Turnstile, so guessing is limited (8 characters from a 31-character alphabet is about 40 bits). Changing the policy, creating, revoking and deleting codes are logged to the activity log in the way the App settings page and admin resources already do.

### Docs

One paragraph in `deploy/README.md` (near "Create the first owner") saying a self-hoster can close or restrict sign-ups under **App settings > Sign-ups**, and that the first owner can only be made by registering (no command creates a user), so leave the policy Open until that account exists and has been promoted.

## Testing

Feature tests with factories for an `InviteCode` factory (states: expired, revoked, used up, email-locked). Cover: each policy at the Register page (renders, redirects, requires a code); a valid, invalid, expired, revoked, used-up and email-mismatched code; `?code=` prefill; Google registration allowed, refused and carrying a pending invite, with an existing Google user still signing in under Closed; atomic single-use (the second redemption of a spent code fails and creates no user); a shared code stopping at its limit; the admin resource (generate N codes, shared code, revoke, copy link, delete-only-if-unused); the setting default is Open; Admin > Users > Create works under Closed; the Register and welcome links hidden when Closed.

## Left to the builder

File placement under `app/Actions` and `app/Filament`, the exact Filament form components, the wording of messages not given above, and whether the "pending invite" lives under one session key or two.

## Calls made without being asked (open to change)

- The same invalid-code message for every failure, to avoid revealing which codes exist.
- Status is derived, not stored.
- Used codes can be revoked but not deleted.
- Generate-invites limit of 50 per action.
- Email lock compared case-insensitively.
- Existing users are never affected by a policy change, and unused codes are simply ignored while the policy is not Invite only.
