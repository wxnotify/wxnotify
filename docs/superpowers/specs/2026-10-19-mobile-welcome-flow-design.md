# Mobile welcome flow — Design

Source: owner conversation, 2026-10-19.

## Intent and why

A subscriber's first sight of the app today is a single "enable push" explainer (`PushPrompt`, shown by `AuthCallback` right after sign-in) and then the Alerts screen. Nothing says what WxNotify is for, what it offers, or why it asks for notification and location permissions; location sharing is only reachable later from the Account tab. A short welcome flow on the first sign-in on a phone teaches the app at a high level and asks for each permission only after saying why, so the operating system's dialog is never the first the subscriber hears of it and more subscribers end up with the permissions that make the app useful.

## Goals

- On the first sign-in on a phone, the subscriber walks through five short screens: **Welcome, What you get, Push, Location, All set**, then lands on Alerts.
- Each permission screen explains what the app does with the permission and why it is worth allowing, then offers a button that triggers the system dialog, and a "Not now". Declining never blocks the flow, and both can be turned on later from Account.
- The Location screen is offered only when the subscriber has a free location slot (sharing the phone's location creates a place linked to the phone, which counts against the plan's separate phone-location allowance: Free 1, Plus 2, Pro unlimited); otherwise it is skipped and the "All set" screen carries a one-line note saying the plan's places are full.
- A permission screen is skipped only when asking cannot help or is already settled: the OS has **denied** it (the app cannot ask again; Account explains how to change it in the phone's settings), or the feature is already **on** in the app (push registered / location sharing running). Where the OS has granted the permission but the app has not turned the feature on (for example a reinstall), the explainer still shows and its button turns the feature on without a system dialog. The flow never turns a feature on silently, and a skipped screen changes no state (it records no decline).
- The flow can be left at any time with **Skip** (top right): it marks the flow seen and asks for nothing.
- It shows **once per install of the app**: signing out and back in on the same phone does not show it again; a reinstall or a new phone does. Subscribers already signed in when they update the app do not see it.
- It can be replayed from a new **Welcome tour** row in Settings.

## Non-goals

- No account-level "has onboarded" state on the server: the flow is per phone, and the phone's own storage decides.
- No change to what the permissions do (push registration, the location beacon, tier limits); the flow only calls the existing services (`PushRegistration`, `LocationSharing`).
- No per-screen analytics, A/B tests, translated copy, illustrations beyond simple icons, or video.
- Not in this design: the web dashboard's own first-run experience (the Filament onboarding journey for subscribers is a separate, parked project).

## Anti-goals

- The flow must never trap a subscriber: every screen can be skipped or declined, a failed permission or server call never blocks "Next", and a subscriber who is signed out mid-flow is sent to sign-in, not stuck.
- It must not nag: it appears once, a decided OS permission is not re-asked, and Skip is respected.
- Declining push or location in the flow must leave exactly the state an Account-tab decline leaves today (declined push is remembered, location stays off).
- It must not make the first sign-in on a phone slower or fragile: a failed or slow "free slot" lookup is treated as "no slot" and the Location screen is skipped, never an error.

## Constraints

- NativePHP v4 app in `mobile/`: screens are `NativeComponent` classes registered in `routes/mobile.php` with EDGE markup (`native:*` elements) and Tailwind classes, never web views; partial views cannot use `$this`; theme tokens and the typed icon enums are used instead of emoji (the design mockups used emoji as stand-ins only); `native:validate` must pass; never run `native:run`, `native:jump` or `native:watch` (ask the owner which platform).
- Existing pieces to reuse, not duplicate: `PushPrompt` and `LocationPrompt` (the explainers, whose "Not now"/outcome handling and copy are the starting point), `PushRegistration::shouldPrompt()/enable()/decline()`, `LocationSharing::enable()` (returns `started`, `limit`, `offline`, or another failure), `AuthCallback` (today it redirects to `/enable-push` or `/`), the `RequiresSignIn` trait, the `HandlesPushToken` trait (the OS token event reaches only the mounted screen's `#[On]` handler, so any screen that triggers enrolment must carry it), and `Account`'s rows for the later opt-in.
- Whether a free phone-location slot exists is learned from the server: the alert-rule options response gains `can_share_phone_location` (the existing `can_create_location` keeps meaning "may save another stationary place"); the lookup runs once while the flow starts and its failure means "no slot".
- "Once per install" means the seen flag lives in storage that is deleted with the app and is **not** wiped by sign-out. `SignOut::clearLocal()` wipes `LocalCache` and `PushState`, so the flag needs its own store; iOS Keychain-backed storage (the secure-storage plugin) survives an uninstall and so is the wrong place.
- Existing signed-in users must not see the flow on update: the flag has to be treated as "seen" for an app that already has a stored sign-in token when the new version first runs (the builder decides how: for example, set the flag when a token exists at launch and the flag was never written).
- The app's UI copy is plain language, short sentences, no jargon; my drafts of the copy (see below) are the owner's to edit.

## What was decided (with the owner)

- Option A from the mockups: **five short screens**, one idea each, progress dots, Next on the first two, permission buttons on the two permission screens, "Go to Alerts" at the end.
- Location is explained for everyone but **offered only when there is a free slot**.
- **Per phone, once per install** (not per account, not after every sign-in).
- Mockups: `.superpowers/brainstorm/` (session `welcome-flow.html`; not committed).

## Draft copy (owner to edit)

1. **Welcome to WxNotify** — Weather alerts for the places you care about, on your phone.
2. **What you get** — Alerts you set up once. Current warnings and advisories for your places. A map and every National Weather Service product.
3. **Get alerts instantly** — Allow notifications so a warning reaches you the moment it's issued, even when the app is closed. [Allow notifications] [Not now]
4. **Follow your phone** — Share this phone's location and we'll watch the weather wherever you are. It uses one of your places. [Share location] [Not now]
5. **You're set** — Add places and alert rules any time from Settings. [Go to Alerts]. When no slot: "Your plan's places are full, so sharing this phone's location isn't available."

## Left to the builder

The component structure (one flow component with steps versus one component per screen; how Back/Next and the dots work; swipe versus buttons only), the seen-flag store and the "treat existing signed-in installs as seen" check, how the free-slot lookup is made and cached for the session, the exact OS-permission checks per platform (`PushNotifications::checkPermission()` is known; location permission state comes from the geolocation plugin), the route names, the Settings row's position, how `AuthCallback` chooses between the flow and `/`, how the existing `/enable-push` route and `PushPrompt` are folded in or retired, the tests (flow ordering and skipping rules, Skip, "Not now" paths, per-install flag surviving sign-out, existing sign-in treated as seen, slot lookup failure → skipped, replay from Settings) and the on-device checks.

## Device checks owed (cannot be done in tests)

- iOS and Android: a first sign-in on a fresh install walks all five screens; each system dialog appears only after its explainer; "Not now" and Skip leave the matching state in Account; granting both leaves push registered and location sharing on.
- A phone whose OS permissions were decided earlier skips those screens.
- Signing out and back in goes straight to Alerts; a reinstall shows the flow again; the Settings "Welcome tour" row replays it.
- A Free account whose single location slot is taken never sees the Location screen and sees the note on the last screen.

## My calls (for the owner to check)

- Skip leaves the flow, asks for nothing, and marks it seen.
- A permission screen is skipped only when the OS has denied it or the feature is already on in the app; an OS grant without the feature on still shows the explainer (no silent enrolment, and a skipped screen records nothing).
- Replay from Settings runs the same five screens with the same skip rules; a subscriber who earlier tapped "Not now" sees the explainers again (that is the point of replaying), and finishing or skipping a replay changes nothing (the seen flag is already set).
- The free-slot test is `can_share_phone_location`. If it is false the Location screen is skipped with the note on the last screen; if it is true but `LocationSharing::enable()` still answers `limit` (the flag was stale), the flow moves on to "All set" with the same note. A phone-linked place left over from an earlier install is not looked for.
- Existing signed-in installs are treated as seen after the update.
- A "Welcome tour" row in Settings replays the flow.
- A failed free-slot lookup counts as "no slot".
