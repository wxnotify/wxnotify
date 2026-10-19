# Mobile Welcome Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show a five-screen welcome flow (Welcome, What you get, Push, Location, All set) once per install of the mobile app, right after the first sign-in, explaining each permission before asking.

**Architecture:** A small `Welcome` service decides whether the flow shows (a "seen" flag that survives sign-out) and which steps apply (skip rules from push/location state and the server's phone-location slot). One `WelcomeFlow` native component renders the steps from that list; `AuthCallback` routes into it instead of the old single push explainer, and a Settings row replays it.

**Tech Stack:** NativePHP v4 app in `mobile/` (EDGE `native:*` markup, Tailwind classes, theme tokens), Pest with `Native::test(...)`, the existing `PushRegistration`, `LocationSharing`, `PushState`, `WxNotifyApi` services.

**Spec:** `docs/superpowers/specs/2026-10-19-mobile-welcome-flow-design.md`

## Global Constraints

- Work in `mobile/` only (the backend already exposes `can_share_phone_location` in `GET /api/v1/alert-rule-options` from the phone-allowance change; a response without the key means "no slot"). Never run `native:run`, `native:jump` or `native:watch`; tell the owner which platform to build for.
- Read `mobile/CLAUDE.md` guidance and activate the `nativephp-mobile` and `mobile:testing-best-practices` skills before editing. Build screens only with native EDGE markup and Tailwind classes, theme tokens (`bg-theme-*`, `text-theme-*`), `native:icon` with the typed icon enums (never emoji); partial views cannot use `$this` (pass `['screen' => $this]`); `php artisan native:validate` must pass.
- "Seen" flag: `Cache::forever('wxnotify.welcome.seen', true)`, in its OWN key that `LocalCache::wipe()`, `PushState::clear()` and `SignOut` never touch (so sign-out keeps it) and that disappears with the app's data on uninstall. Never put it in secure storage (the iOS Keychain survives an uninstall).
- The flow is marked seen the moment it starts (so quitting halfway never brings it back). An already signed-in install that has never written the flag is treated as seen (set on the first launch of the new version, in `AlertsScreen::mount()`, when a token exists and the flag is unset).
- A permission screen is skipped only when the OS has denied push (`PushNotifications::checkPermission() === 'denied'`) or the feature is already on in the app (`PushState::wantsPush()`; `LocationSharing::state()['status'] !== 'off'`), or, for Location, when the phone slot is not free. An OS grant without the feature on still shows the explainer. A skipped screen changes no state. Location's OS permission is read asynchronously by the plugin and is NOT pre-checked in this slice: an OS-denied location surfaces as `LocationSharing::enable()` outcome `failed` and gets the same on-screen note as the other failures.
- "Not now" for push calls `PushRegistration::decline()` exactly as the old prompt did; "Allow notifications" calls `PushRegistration::enable()`; "Share location" calls `LocationSharing::enable()` and handles `started|limit|offline|failed` like `LocationPrompt` (limit moves on with the full-plan note; offline/failed move on with a one-line note on the last screen). Skip leaves the flow asking nothing. Nothing in the flow may throw into a screen, and a failed free-slot lookup counts as "no slot".
- Replay (`/welcome/tour`) runs the same steps and rules, never changes the seen flag, and "Skip"/finish returns to Settings.
- Pint on touched files (`vendor/bin/pint --dirty --format agent` in `mobile/`); mobile tests `cd mobile && vendor/bin/pest --compact`. Commit each task on `main` with the trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`; never push.

## Review Focus

1. A first sign-in on a fresh install reaches the welcome flow exactly once; signing out and back in, or an already signed-in install updating the app, goes straight to Alerts (Tasks 1 and 3).
2. No screen can trap the subscriber: Skip works on every screen, "Not now" never blocks, and a failed server call (slot lookup, location enable) moves on with a note rather than an error (Task 2).
3. Declining leaves the same state as the Account-tab decline does today, and a skipped screen leaves no state behind (Task 2).
4. The Location screen never appears for a subscriber without a free phone slot, and the last screen says so (Task 2).
5. The seen flag survives sign-out and is not set by replay (Tasks 1 and 3).

---

### Task 1: The seen flag and the step list

**Files:**
- Create: `mobile/app/Support/WelcomeState.php`, `mobile/app/Services/Welcome.php`
- Test: `mobile/tests/Feature/WelcomeTest.php`

**Interfaces:**
- Consumes: `PushState::wantsPush()`, `PushRegistration::permission()`, `LocationSharing::state()`, `WxNotifyApi::alertRuleOptions()`, `TokenStore::token()`.
- Produces: `WelcomeState::seen(): bool`, `markSeen(): void` (own cache key, see Global Constraints). `Welcome::shouldShow(): bool` (= not seen). `Welcome::markLegacyInstallSeen(): void` (when a token exists and the flag is unset, set it; otherwise nothing). `Welcome::steps(): list<'welcome'|'offer'|'push'|'location'|'done'>` always starts `welcome`, `offer`, ends `done`, includes `push` only when push is not already on and the OS has not denied it, includes `location` only when sharing is off AND `alertRuleOptions()['can_share_phone_location'] === true` (a thrown `ApiException` or a missing key = excluded). `Welcome::locationNote(): bool` is not needed: the "done" screen reads `$noSlot` from the component (Task 2), computed as "sharing off and the slot lookup said no".

- [ ] **Step 1: Write the failing tests:** `the flow shows until it has been marked seen`; `signing out keeps the seen flag` (call `SignOut::clearLocal()`); `an install with a stored token and no flag is treated as seen`, `a fresh install with no token is not`; `steps include push and location when push is off and a slot is free`; `steps skip push when push is already on or the OS denied it`; `steps skip location when sharing is on, when the server says no slot, when the key is missing, and when the lookup throws` (fake `Http` and the `PushNotifications`/`System` facades like `PushRegistrationTest.php`).
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement** both classes (cache key only in `WelcomeState`; `Welcome::steps()` makes at most one API call and none when the location step is already ruled out by local state).
- [ ] **Step 4: Run; expect PASS.** Pint.
- [ ] **Step 5: Commit** `App: the welcome flow's seen flag and step rules`.

---

### Task 2: The WelcomeFlow screen

**Files:**
- Create: `mobile/app/NativeComponents/WelcomeFlow.php`, `mobile/resources/views/native/welcome-flow.blade.php`
- Modify: `mobile/routes/mobile.php`
- Test: `mobile/tests/Feature/WelcomeFlowTest.php`

**Interfaces:**
- Consumes: Task 1's `Welcome::steps()`; `PushRegistration::enable()/decline()`; `LocationSharing::enable()`; the traits `RequiresSignIn` and `HandlesPushToken` (the OS push-token event reaches only the mounted screen's `#[On]` handler, so this screen must carry it).
- Produces: route `/welcome` (first run) and `/welcome/tour` (replay), both `WelcomeFlow::class`; component state `public array $steps`, `public int $index`, `public bool $replay`, `public bool $noSlot`, `public ?string $note`; methods `next()`, `skip()`, `allowPush()`, `notNowPush()`, `shareLocation()`, `notNowLocation()`, `finish()`. `skip()`/`finish()` go to `/` on first run (`replace`) and back to `/settings` on replay. Copy per screen is the spec's "Draft copy" verbatim, on the screens' own themed layout with progress dots and a top-right Skip (use `native:icon`/typed icons, not emoji).

- [ ] **Step 1: Write the failing tests** (`Native::test(WelcomeFlow::class)` with fakes as in `PushTokenScreensTest.php`): `it walks the five steps with Next and finishes on Alerts`; `Skip on any step leaves for Alerts and asks for nothing` (no `enroll`, no enable request); `Allow notifications enables push and moves on`; `Not now on push declines (PushState declined) and moves on`; `Share location starts sharing and moves on`; `location limit moves on and the last screen shows the full-plan note`; `location offline or failed moves on with a one-line note`; `the push and location steps are absent when Task 1's rules exclude them` (the dots/steps count follows `steps()`); `a signed-out visitor is sent to sign-in`; `replay does not touch the seen flag and Skip returns to settings`.
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement** the component and view; the first-run route marks the flag seen in `mount()` (the replay route does not); route registration in `routes/mobile.php`.
- [ ] **Step 4: Run; expect PASS**, then `php artisan native:validate`. Pint.
- [ ] **Step 5: Commit** `App: the five-screen welcome flow`.

---

### Task 3: Wire it in, retire the old prompt, replay row

**Files:**
- Modify: `mobile/app/NativeComponents/AuthCallback.php`, `mobile/app/NativeComponents/AlertsScreen.php` (the one-line legacy check in `mount()`), `mobile/app/NativeComponents/SettingsScreen.php` and `mobile/resources/views/native/settings-screen.blade.php` (a "Welcome tour" row), `mobile/routes/mobile.php`, `.ai/rules/delivery.md` (a few lines on the flow), the mobile `AccountScreen` push/location "turn on" paths only if they link to the removed prompt
- Delete: `mobile/app/NativeComponents/PushPrompt.php`, `mobile/resources/views/native/push-prompt.blade.php`, route `/enable-push`, `mobile/tests/Feature/PushPromptTest.php` (move any still-relevant assertions into `WelcomeFlowTest.php` first)
- Test: `mobile/tests/Feature/SignInScreensTest.php`, `mobile/tests/Feature/WelcomeFlowTest.php`, `mobile/tests/Feature/SettingsScreenTest.php`, `mobile/tests/Feature/AlertsScreenTest.php`

**Interfaces:**
- Consumes: `Welcome::shouldShow()`, `Welcome::markLegacyInstallSeen()`, route `/welcome`, `/welcome/tour`.
- Produces: `AuthCallback` replaces with `/welcome` when `Welcome::shouldShow()` else `/`; the old `shouldPrompt()` logic is no longer used by `AuthCallback` (keep `PushRegistration::shouldPrompt()` only if something else uses it, otherwise remove it and its test); `AlertsScreen::mount()` calls `Welcome::markLegacyInstallSeen()` once when signed in; Settings gets a "Welcome tour" list row (`navigate('/welcome/tour')`).

- [ ] **Step 1: Write the failing tests:** `a valid callback on a fresh install goes to the welcome flow`; `a valid callback after the flow was seen goes straight to Alerts`; `an existing signed-in install is marked seen when Alerts opens` (token present, flag unset → set; no redirect); `Alerts does not mark a fresh install seen before sign-in`; `the Settings screen has a Welcome tour row that opens /welcome/tour`; update the existing sign-in tests that expected `/enable-push`.
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement** the wiring, delete the old prompt files and route, add the rule note to `delivery.md` (the flow's skip rules, the seen-flag store, replay, the deliberate no-OS-location-precheck), and fix any other reference (`grep -rn "enable-push\|PushPrompt" mobile/app mobile/resources mobile/routes mobile/tests`).
- [ ] **Step 4: Run the new tests, then the whole mobile suite** (`cd mobile && vendor/bin/pest --compact`) and `php artisan native:validate`; expect PASS. Pint.
- [ ] **Step 5: Commit** `App: sign-in leads to the welcome flow once, with a replay row in Settings`.

---

## Device checks owed to the owner (after a rebuild; ask which platform, never run the build)

A fresh install: sign in → five screens, each system dialog only after its explainer, "Not now" and Skip leave Account showing push/location off; granting both turns both on. Sign out and back in → straight to Alerts. A Free account whose phone slot is taken → no Location screen and the note on the last screen. Settings → Welcome tour replays the flow without changing anything.
