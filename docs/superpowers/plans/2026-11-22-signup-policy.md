# Sign-up Policy and Invite Codes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an owner choose, at runtime, whether new accounts are Open, Invite only or Closed, and manage invite codes on an admin page.

**Architecture:** One runtime setting (`SignUpSettings.policy`) and one gate class (`SignUpGate`) that every sign-up door calls: the dashboard `Register` page and the Google registration callback. Invite codes live in an `invite_codes` table; a code is spent in the same transaction that creates the user. The register route stays registered; the page refuses when Closed.

**Tech Stack:** Laravel 13, PHP 8.4, Filament v5, spatie/laravel-settings, dutchcodingcompany/filament-socialite, Pest, Pint.

**Spec:** `docs/superpowers/specs/2026-11-22-signup-policy-design.md`

## Global Constraints

- Read the `.ai/rules` files that match each path before editing it (`index.md` maps globs: `app/Actions/Account/**`, `app/Actions/**`, `app/Models/**`, `app/Filament/**`, `app/Filament/Dashboard/Pages/Auth/**`, `tests/Feature/**`). `models.md`: call `logOnly([...])` before `logOnlyDirty()` on `LogsActivity`.
- Use `php artisan make:` commands with `--no-interaction` for models, migrations, factories, enums, resources, settings migrations and tests (`--pest`). Run PHP in the dev stack's `front-end` container: `docker compose exec front-end php artisan test --compact <file>` (the host cannot resolve `pgsql`). Run `vendor/bin/pint --dirty --format agent` before finishing each task.
- Exact user-facing messages (copy verbatim):
  - Closed: `Sign-ups are closed. Ask the administrator for an account.`
  - Any bad code (unknown, expired, revoked, used up): `That invite code isn't valid.`
  - Email lock mismatch: `This invite is for a different email address.`
- Default policy is `open`, so existing installs behave exactly as before. Cloud and self-hosted behave the same. Existing users and **Admin > Users > Create** are never affected by the policy.
- Codes are matched after normalising: trimmed, upper-cased, everything except `A-Z0-9` removed. Generated codes are 8 characters from `ABCDEFGHJKMNPQRSTUVWXYZ23456789`, shown as `ABCD-EFGH`. Custom codes: letters, digits and dashes, at least 4 characters after normalising.
- `users.invite_code_id` is not mass-assignable (`User` uses `#[Fillable]`); set it with `forceFill`.
- Do not commit unless the user asks. Each task ends with green tests and a suggested commit message.
- Do not add dependencies.

## Review Focus

Failure modes the spec implies; each has a test in the owning task.

1. An existing user (with or without a linked Google account) still signs in under Closed and Invite only (Task 5).
2. A single-use code cannot create two accounts: the second redemption fails and creates no user (Task 3).
3. If creating the user fails, the code is not spent (Task 3).
4. Under Open, a supplied code is ignored and not recorded; under Closed a valid code does not help (Task 3).
5. A code typed with different case, spaces or dashes matches; an expiry at the exact current second counts as expired; an email lock compares case-insensitively (Tasks 2 and 3).

---

### Task 1: The policy setting and its admin tab

**Files:**
- Create: `app/Enums/SignUpPolicy.php`, `app/Settings/SignUpSettings.php`, `database/settings/2026_11_22_100001_create_sign_up_settings.php`
- Modify: `app/Filament/Admin/Pages/AppSettings.php` (mount, form tabs, save)
- Test: `tests/Feature/AppSettingsPageTest.php` (add cases)

**Interfaces:**
- Produces: `App\Enums\SignUpPolicy` string enum, cases `Open = 'open'`, `InviteOnly = 'invite_only'`, `Closed = 'closed'`, with `label(): string`. `App\Settings\SignUpSettings` with `public string $policy;` and group `sign_up`. Later tasks read `SignUpPolicy::from(app(SignUpSettings::class)->policy)`.

- [ ] **Step 1: Write failing tests** in `AppSettingsPageTest.php`: (a) the page loads `policy` as `'open'` by default (`assertFormSet(['policy' => 'open'])`); (b) filling `policy` with `invite_only` and calling `save` stores it (`app(SignUpSettings::class)->policy === 'invite_only'`); (c) changing the policy writes an activity-log row whose description is `Sign-up policy changed` and whose properties hold `from` and `to`; (d) saving without changing it writes no such row.
- [ ] **Step 2: Run** `docker compose exec front-end php artisan test --compact tests/Feature/AppSettingsPageTest.php`. Expected: the new tests FAIL (class `SignUpSettings` not found).
- [ ] **Step 3: Create the enum, the settings class and the settings migration** (`php artisan make:enum`, `make:settings-migration`; migration adds `policy` with default `'open'` in group `sign_up`; follow `database/settings/2026_10_19_100001_add_phone_location_allowance.php` for the `down()`).
- [ ] **Step 4: Edit `AppSettings`:** include `SignUpSettings` in `mount()`'s fill and in `save()`'s class list; add a `Tab::make('Sign-ups')` after Feature flags with a required `Select::make('policy')` over `SignUpPolicy` (labels `Open`, `Invite only`, `Closed`) and helper text per value (Open: anyone can create an account. Invite only: sign-up needs a valid invite code, managed under Subscribers > Invite codes. Closed: only staff can create accounts, under Users). In `save()`, read the policy before and after; when it changed call `activity('settings')->causedBy(auth()->user())->withProperties(['from' => ..., 'to' => ...])->log('Sign-up policy changed')` (read `.ai/rules/actions.md` on `CauserResolver::withCauser`; a Filament page request has a session, so plain `causedBy` is fine here).
- [ ] **Step 5: Run the tests again.** Expected: PASS.
- [ ] **Step 6: Pint, then suggested commit:** `Sign-up policy: add the setting and its App settings tab`.

---

### Task 2: Invite code model, tables and factory

**Files:**
- Create: `app/Models/InviteCode.php`, `app/Enums/InviteCodeStatus.php`, `database/factories/InviteCodeFactory.php`, migrations `create_invite_codes_table` and `add_invite_code_id_to_users_table` (dated `2026_11_22_11000x`)
- Modify: `app/Models/User.php` (add `inviteCode(): BelongsTo`)
- Test: `tests/Feature/InviteCodeTest.php`

**Interfaces:**
- Produces:
  - Columns (`invite_codes`): `id`, `code` (string, unique, normalised), `note` (nullable string), `max_uses` (nullable unsigned int; null = unlimited), `used_count` (unsigned int, default 0), `expires_at` (nullable timestamp), `email` (nullable string, stored lower-case), `revoked_at` (nullable timestamp), `created_by` (nullable FK to `users`, null on delete), timestamps. `users.invite_code_id`: nullable FK to `invite_codes`, null on delete.
  - `App\Enums\InviteCodeStatus` string enum: `Active`, `UsedUp`, `Expired`, `Revoked`, with `label(): string` and `color(): string`.
  - `InviteCode`: `static normalise(?string $code): string`; `static generateCode(): string`; `displayCode(): string`; `status(): InviteCodeStatus`; scopes `active()`, `usedUp()`, `expired()`, `revoked()` (same definitions as `status()`, checked in that order Revoked, Expired, UsedUp, Active); relations `users(): HasMany`, `creator(): BelongsTo`. `LogsActivity` logging `note`, `max_uses`, `expires_at`, `email`, `revoked_at` (use `logOnly([...])` then `logOnlyDirty()`).
  - `InviteCodeFactory` states: `expired()`, `revoked()`, `usedUp()`, `forEmail(string $email)`, `shared(int $maxUses)`; default is single-use, active, generated code.

- [ ] **Step 1: Write failing tests** in `InviteCodeTest.php`: `normalise(' ab-cd 12 ')` returns `ABCD12`; `normalise(null)` returns `''`; `generateCode()` is 8 characters, only from the allowed alphabet, over 200 calls never contains `0 O 1 I L`; `displayCode()` of an 8-character code is `ABCD-EFGH` and of a custom `FRIENDS2026` is `FRIENDS2026`; `status()` for each factory state, including a code whose `expires_at` equals `now()` being `Expired` (use `freezeTime()`); precedence: a code both revoked and expired is `Revoked`; each scope returns exactly the matching factory rows; `code` is unique at the database; deleting the creator leaves the code with `created_by` null; deleting a code leaves its users with `invite_code_id` null.
- [ ] **Step 2: Run** `... --compact tests/Feature/InviteCodeTest.php`. Expected: FAIL (class not found).
- [ ] **Step 3: Generate** the model, factory and migrations with `make:model InviteCode -f --no-interaction`, `make:migration`, `make:enum`; fill in as above; add `inviteCode()` to `User`.
- [ ] **Step 4: Run migrations and the tests** (`docker compose exec front-end php artisan migrate --no-interaction`, then the test file). Expected: PASS.
- [ ] **Step 5: Pint, suggested commit:** `Invite codes: add the model, tables and factory`.

---

### Task 3: The gate

**Files:**
- Create: `app/Actions/Account/SignUpGate.php`, `app/Actions/Account/SignUpDecision.php`, `app/Actions/Account/SignUpRefused.php`, `app/Enums/SignUpRefusal.php`
- Test: `tests/Feature/SignUpGateTest.php`

**Interfaces:**
- Consumes: `SignUpPolicy`, `SignUpSettings` (Task 1); `InviteCode` and its factory (Task 2).
- Produces:
  - `enum SignUpRefusal: string { Closed; InvalidCode; EmailMismatch }` with `message(): string` returning the three exact messages above.
  - `final readonly class SignUpDecision { public bool $allowed; public ?SignUpRefusal $refusal; public function message(): ?string }`.
  - `class SignUpRefused extends RuntimeException { public function __construct(public readonly SignUpRefusal $refusal) }`, whose message is the refusal's message.
  - `class SignUpGate`:
    - `policy(): SignUpPolicy`
    - `check(?string $code, ?string $email): SignUpDecision`: read-only. Open allows; Closed refuses `Closed`; Invite only needs a redeemable code (exists, not revoked, not expired, uses left); a blank or unknown code refuses `InvalidCode`; when `$email` is not null and the code has an email they must match case-insensitively, else `EmailMismatch`.
    - `admit(?string $code, string $email, Closure $createUser): User`: runs in `DB::transaction`; Open calls `$createUser()` and ignores the code; otherwise re-checks with the code row locked (`lockForUpdate`), calls `$createUser()`, then `forceFill(['invite_code_id' => $invite->id])->save()` on the user and `increment('used_count')` on the code; throws `SignUpRefused` on refusal.
    - `rememberCode(string $code): void`, `rememberedCode(): ?string`, `forgetCode(): void`: the pending invite, held in the session under one key as a normalised code.

- [ ] **Step 1: Write failing tests** in `SignUpGateTest.php` (set the policy with `app(SignUpSettings::class)->policy = '...'; ->save()` in a helper):
  - Open: `check(null, 'a@b.test')` allowed; a code is ignored: `admit('X', ...)` creates the user and leaves `invite_code_id` null and every code's `used_count` unchanged.
  - Closed: `check` refuses with `Closed` and the exact message; `admit` throws `SignUpRefused` and creates no user, even with a valid code.
  - Invite only: no code, unknown code, expired, revoked and used-up codes each refuse `InvalidCode` with the exact message; an expiry equal to now refuses (frozen clock).
  - A valid code typed as `abcd-efgh` and as ` ABCD EFGH ` both match.
  - Email lock: matching address in different case allowed; another address refuses `EmailMismatch`; `check($code, null)` does not evaluate the lock.
  - `admit` with a valid single-use code returns the new user with `invite_code_id` set and `used_count` 1; a second `admit` with the same code throws `SignUpRefused` (`InvalidCode`) and `User::count()` is unchanged.
  - A shared code with `max_uses` 3 admits three users and refuses the fourth; `max_uses` null never runs out.
  - If the `$createUser` closure throws, the exception propagates, `used_count` is still 0 and no user exists.
  - `rememberCode('ab-cd')` then `rememberedCode()` returns `ABCD`; `forgetCode()` clears it.
- [ ] **Step 2: Run** `... --compact tests/Feature/SignUpGateTest.php`. Expected: FAIL (class not found).
- [ ] **Step 3: Implement** the four classes per the interfaces; follow the structure of the neighbouring `app/Actions/Account/*` classes for invocation style and read `.ai/rules/account.md` and `.ai/rules/actions.md` first.
- [ ] **Step 4: Run the tests.** Expected: PASS.
- [ ] **Step 5: Pint, suggested commit:** `Sign-up gate: one class decides who may create an account`.

---

### Task 4: The Register page, login link and welcome link

**Files:**
- Create: `app/Filament/Dashboard/Pages/Auth/Login.php`
- Modify: `app/Filament/Dashboard/Pages/Auth/Register.php`, `app/Providers/Filament/DashboardPanelProvider.php` (`->login(Login::class)`), `resources/views/welcome.blade.php`
- Test: `tests/Feature/RegisterTest.php` (add cases), `tests/Feature/SignUpLinksTest.php`

**Interfaces:**
- Consumes: `SignUpGate` (Task 3) and its `check`, `admit`, `rememberCode`, `forgetCode`.
- Produces: the Register form gains `TextInput::make('invite_code')` (first field, `->dehydrated(false)`, visible and required only when the policy is Invite only, prefilled from `?code=`); `Login::getSubheading()` returns null when Closed, otherwise the parent's.

- [ ] **Step 1: Write failing tests.** In `RegisterTest.php` (reuse its `fakeSuccessfulTurnstile()`):
  - Open: the existing tests still pass unchanged, and an `invite_code` field is not shown (`assertFormFieldHidden('invite_code')`).
  - Closed: `livewire(Register::class)` redirects to the login URL; and calling `register` on a component mounted while Open, after the policy is set to Closed, creates no user and shows the Closed message as an error.
  - Invite only: the `invite_code` field is shown and required; with a valid single-use code the account is created, `invite_code_id` is set and the code is spent; an invalid, expired, revoked and used-up code each give `assertHasFormErrors(['invite_code'])` and create no user; an email-locked code with another email fails with the mismatch message; `?code=ABCD-EFGH` in the request prefills the field (`Livewire::withQueryParams(['code' => ...])`); a valid `?code=` also calls `rememberCode`; an invalid one is not remembered.
  - Turnstile still applies in Invite only (an empty token still fails).
  - `SignUpLinksTest.php`: the login page contains the "Register" action under Open and Invite only and not under Closed; `GET /` (the welcome page) shows the Register link under Open and not under Closed; and under Closed, staff still create a user through `CreateUser` (`app/Filament/Admin/Resources/Users`) and an existing user still signs in at the login page.
- [ ] **Step 2: Run** `... --compact tests/Feature/RegisterTest.php tests/Feature/SignUpLinksTest.php`. Expected: the new tests FAIL.
- [ ] **Step 3: Implement.**
  - `Register::mount()`: when Closed, flash a Filament danger notification with the Closed message and redirect to `Filament::getLoginUrl()`; otherwise call the parent, then prefill `invite_code` from `request()->query('code')` and `rememberCode` it when `check($code, null)->allowed` and the policy is Invite only.
  - `Register::handleRegistration()`: call `SignUpGate::admit($this->data['invite_code'] ?? null, $data['email'], fn () => parent::handleRegistration($data))`; convert `SignUpRefused` to `ValidationException::withMessages([...])` keyed `data.invite_code` for `InvalidCode` and `EmailMismatch`, `data.email` for `Closed`; call `forgetCode()` after success.
  - The `invite_code` field's validation rule asks `check($value, $get('email'))` so the lock shows before submit.
  - `Login` extends `Filament\Auth\Pages\Login`; register it with `->login(Login::class)`.
  - `welcome.blade.php`: wrap the Register link in `@if (Route::has('register') && app(\App\Actions\Account\SignUpGate::class)->policy() !== \App\Enums\SignUpPolicy::Closed)`.
- [ ] **Step 4: Run the tests again.** Expected: PASS. Also run `tests/Feature/RegisterTest.php` in full to confirm nothing else regressed.
- [ ] **Step 5: Pint, suggested commit:** `Register page: honour the sign-up policy and invite codes`.

---

### Task 5: Google registration

**Files:**
- Modify: `app/Providers/Filament/DashboardPanelProvider.php` (the `FilamentSocialitePlugin` block)
- Test: `tests/Feature/GoogleSignUpPolicyTest.php`

**Interfaces:**
- Consumes: `SignUpGate::check`, `admit`, `rememberedCode`, `forgetCode`.
- Produces: `->registration(fn (string $provider, SocialiteUserContract $oauthUser, ?Authenticatable $user): bool => ...)` that allows when `$user` is not null or the gate's `check(rememberedCode(), $oauthUser->getEmail())->allowed`; `createUserUsing` wraps the existing `User::create(...)` and `markEmailAsVerified()` in `admit(rememberedCode(), $email, ...)`, then `forgetCode()`.

- [ ] **Step 1: Find how the plugin is exercised in tests.** Search `tests/` for `Socialite` or `FilamentSocialitePlugin`; if there is no precedent, test through the plugin object: `Filament::getPanel('dashboard')->getPlugin('filament-socialite')`, call its registration closure and its create-user callback directly with a fake `SocialiteUserContract` (a small anonymous class or `Mockery::mock`), since the OAuth redirect itself is not testable offline.
- [ ] **Step 2: Write failing tests:**
  - Open: registration closure returns true; `createUserUsing` creates a verified user, no code spent.
  - Closed: registration closure returns false for a new email; returns true for an email that already has a user (the closure receives that user), so the existing user still signs in (Review Focus 1); same for a user who already has a linked Google account.
  - Invite only: false without a remembered code; true after `rememberCode` with a valid code; false for a remembered code that has since been used up or revoked; false when the code's email lock differs from the Google email; an existing user still true.
  - `createUserUsing` under Invite only creates the user, sets `invite_code_id`, spends the code and clears the remembered code.
- [ ] **Step 3: Run** `... --compact tests/Feature/GoogleSignUpPolicyTest.php`. Expected: FAIL.
- [ ] **Step 4: Implement** the two closures in `DashboardPanelProvider`, keeping the provider thin (all rules stay in the gate).
- [ ] **Step 5: Check the plugin's refusal text.** Read `vendor/dutchcodingcompany/filament-socialite/resources/lang/en/auth.php` for `registration-not-enabled`; if the wording is wrong for Closed and Invite only, publish an override in `lang/vendor/filament-socialite/en/auth.php` reading `Sign-ups are closed or need an invite code. Ask the administrator.` and note it in the task's summary. Also open the Register page in a browser (Browser pane) under Invite only and note whether it shows a Google button; the spec expects the invite link path to work either way because `?code=` remembers the code on mount.
- [ ] **Step 6: Run the tests again.** Expected: PASS. Pint, suggested commit: `Google sign-up: follow the sign-up policy`.

---

### Task 6: The Invite codes admin page and "Joined with"

**Files:**
- Create: `app/Actions/Account/CreateInviteCodes.php`, `app/Filament/Admin/Resources/InviteCodes/InviteCodeResource.php`, `.../Pages/ListInviteCodes.php`, `.../Tables/InviteCodesTable.php` (`make:filament-resource InviteCode --no-interaction`, then remove the create and edit pages)
- Modify: `app/Filament/Admin/Resources/Users/Tables/UsersTable.php` (a "Joined with" column), `app/Filament/Admin/Resources/Users/Schemas/UserForm.php` (a read-only "Joined with" entry on edit when the user has a code)
- Test: `tests/Feature/CreateInviteCodesTest.php`, `tests/Feature/InviteCodeResourceTest.php`, `tests/Feature/UserResourceTest.php` (add a case)

**Interfaces:**
- Consumes: `InviteCode`, its factory and scopes (Task 2).
- Produces: `CreateInviteCodes::__invoke(int $count, ?string $note, ?CarbonInterface $expiresAt, ?string $email, ?User $by, ?string $code = null, ?int $maxUses = 1): Collection` returning the created codes. Generated codes retry on a unique collision; a custom `$code` is normalised and only valid with `$count` 1. `InviteCodeResource` in navigation group `Subscribers`, label `Invite codes`, list page only.

- [ ] **Step 1: Write failing tests.**
  - `CreateInviteCodesTest`: creates N single-use codes with the note, expiry and creator; `email` is stored lower-case; a custom code is stored normalised; a custom code that normalises to an existing one is refused (throws a validation or domain exception the page turns into a form error); `$maxUses` null makes an unlimited code; a custom code of fewer than 4 characters after normalising is refused; `$count` above 50 is refused.
  - `InviteCodeResourceTest` (staff via `Filament::setCurrentPanel('admin')`, as `AnnouncementResourceTest` does): the table lists codes with a status badge; the status filter returns only matching rows; the **Generate invites** header action with count 3 creates 3 rows and the email field is hidden when the count is above 1; **New shared code** with `max_uses` 5 creates one row; the **Copy invite link** action exists and the URL for a row contains `register?code=` plus its display code; **Revoke** sets `revoked_at` and **Restore** clears it; **Delete** is hidden for a code with `used_count` above 0 and works for an unused one; a non-staff user cannot access the page.
  - `UserResourceTest`: a user with an invite code shows its display code in the "Joined with" column; a user without shows nothing.
- [ ] **Step 2: Run** the three test files. Expected: FAIL.
- [ ] **Step 3: Implement** the action, resource, table, header actions (`generateInvites`: count default 1 min 1 max 50, note, expiry in the future, email shown only when count is 1; `newSharedCode`: optional custom code, `max_uses` nullable integer min 1, expiry, note), row actions (copy link using the same clipboard pattern as `app/Support/Filament/RecoverablePasskeyAuthentication.php`, revoke, restore, delete when unused) and the two Users additions. Read `.ai/rules/filament.md` first. The table's status badge uses `InviteCodeStatus::label()` and `color()`; the Uses column shows `used_count / max_uses` or `used_count / unlimited`.
- [ ] **Step 4: Run the tests again.** Expected: PASS.
- [ ] **Step 5: Look at it in the Browser pane** (preview the dev server, sign in as staff): generate three invites, copy a link, revoke one, and confirm the table reads sensibly at a narrow width. Fix any layout problem found.
- [ ] **Step 6: Pint, suggested commit:** `Admin: invite codes page and Joined with column`.

---

### Task 7: Docs, rules and the full suite

**Files:**
- Modify: `deploy/README.md` (one paragraph near "Create the first owner"), `.ai/rules/` (via `record-rule`)

- [ ] **Step 1: Add the README paragraph** under "Create the first owner": a self-hoster can close or restrict sign-ups under **Admin > App settings > Sign-ups** (Open, Invite only, Closed), manage codes under **Subscribers > Invite codes**, and should leave the policy Open until the first owner account exists and has been promoted, because no command creates a user.
- [ ] **Step 2: Run** `bash deploy/verify.sh`. Expected: `all assertions passed`.
- [ ] **Step 3: Record the rules** with the `record-rule` tool: globs `app/Actions/Account/**` (one rule: every sign-up door, Register and Google, goes through `SignUpGate`; the policy is a runtime setting, never a boot-time `registration()` toggle; a code is spent only inside `admit()`'s transaction) and `app/Models/**` (an invite code's status is derived from its columns, never stored).
- [ ] **Step 4: Run the complete suite in parallel** in the app container as the saved memory "Run the full suite in parallel" describes (6 processes), read the tail of the output, and report any failure by name.
- [ ] **Step 5: Pint (`vendor/bin/pint --dirty --format agent`), suggested commit:** `Sign-up policy: docs and project rules`.
