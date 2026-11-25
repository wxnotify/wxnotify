# Push notification sounds per alert kind: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A subscriber can give each of six kinds of alert its own push sound, per alert rule, chosen in the mobile app's rule wizard (with a tap-to-hear preview), identical on iOS and Android; no choice means the phone's own default sound.

**Architecture:** The backend stores `alert_rules.push_sounds` (kind to sound slug), classifies each alert into a kind, and names the sound in the push (iOS `aps.sound` file, Android notification channel). A new mobile plugin `wxnotify/push-sounds` bundles the WAVs, creates one Android channel per sound and plays previews. The wizard's Finishing touches step gets a Notification sounds section.

**Tech Stack:** Backend (`/Users/jimmy/Documents/WxNotify/wxnotify-nextgen`): Laravel 13, Pest, Pint, tests run in the dev container (`docker compose exec -T front-end php artisan test --compact <file>`). Mobile (`/Users/jimmy/Documents/WxNotify/wxnotify-mobile`): NativePHP v4 plugin (Kotlin, Swift, PHP), Pest with the `Native::test` harness (run on the host: `php vendor/bin/pest --compact <file>`), Pint.

**Spec:** `docs/superpowers/specs/2026-11-25-push-sounds-design.md` (in the backend repo).

## Global Constraints

- Six kinds, string values: `short_fuse_warning`, `other_warning`, `convective_watch`, `other_watch`, `advisory`, `other`. Priority for a multi-product bulletin, highest first, in that order.
- Short-fuse = family Warning with catalog code `TO.W`, `SV.W`, `FF.W`, `DS.W`, `SQ.W`. Convective watch = family Watch with code `TO.A` or `SV.A`. Other warning/watch/advisory = the rest of those families. Everything else (statements, SPC/WPC, discussions, informational, administrative, aviation, and Daily Summary, Threshold, Lightning, Outlook Report rules) = `other`.
- Seven sounds: `alert`, `back`, `bell`, `correct`, `fail`, `harp`, `notification` (slug = file base name, `[a-z0-9_]` only). Labels default to the title-cased slug. `default.wav` is gone; the phone's own default is "no sound set". A shipped sound's file is never replaced (a change is a new slug).
- iOS push sets `aps.sound` to `{slug}.wav`; Android sets `android.notification.channel_id` to `wx_sound_{slug}`. With no sound the payload is exactly today's (`default` sound, `fcm_default_channel`). Test pushes and non-alert pushes never carry a sound. An unknown slug is dropped before it reaches a payload.
- `push_sounds` on create/update: an absent key keeps the stored value; an empty object/array or null clears it. Never tier-gated. The web dashboard never sends it and must not clear it. The webhook transport must never expose it.
- The wizard section shows only when the options payload has `push_sounds` and at least one selected channel has type `mobile_push`. A Weather Alerts rule shows all six slots; every other category shows one "Alert sound" slot (the `other` kind). The save payload carries `push_sounds` only when the section was shown.
- Backend first, then app: an old app never sees the section; a new app against an old backend (no `push_sounds` in the options) hides it.
- Follow `.ai/rules` in both repos for the paths touched; Pint after PHP edits; `php artisan make:` where a command exists; never commit secrets. Native code (Kotlin, Swift) and iOS bundle placement are verified on a device, not by tests.

## Review Focus

- A rule with no sounds sends a payload identical to today's, on both platforms (same `default` sound, same `fcm_default_channel`).
- An unknown kind or slug is refused on write (422) and dropped at send time; a webhook channel never receives `push_sound`.
- A bulletin matching a Tornado Warning segment and an Advisory segment classifies as short-fuse; a Daily Summary or Outlook Report rule maps through the `other` slot.
- Editing a rule on the web dashboard keeps its `push_sounds`; an app save with the section hidden keeps them too.
- A non-Weather-Alerts rule shows exactly one slot; switching a draft's category does not leave stale kinds in the payload.
- Android channel names exist for every shipped sound at app start (`wx_sound_{slug}`), and the plugin's file names equal the backend slugs exactly.

---

### Task 1: Sound list, kinds and classification (backend)

**Work from:** `/Users/jimmy/Documents/WxNotify/wxnotify-nextgen`

**Files:**
- Modify: `config/wxnotify.php`
- Create: `app/Enums/PushSoundKind.php`, `app/Support/PushSounds.php`, `app/Actions/Alerts/ResolveAlertPushSound.php` (via `php artisan make:enum`, `make:class`)
- Test: `tests/Feature/PushSoundsTest.php` (new)

**Interfaces:**
- Produces: `config('wxnotify.push_sounds')`: map slug to label for the seven sounds. `App\Enums\PushSoundKind` (string-backed, the six values above) with `label(): string`, `description(): string`, `priority(): int` (0 = highest). `App\Support\PushSounds` with `static all(): array<string, string>`, `static isKnown(?string $slug): bool`, `static slugs(): list<string>`, `static options(): array{kinds: list<array{value: string, label: string, description: string}>, sounds: list<array{value: string, label: string}>}`. `App\Actions\Alerts\ResolveAlertPushSound` invokable `__invoke(AlertRule $rule, ?IngestedProduct $bulletin = null, ?Collection $segments = null): ?string`: the rule's slug for the alert's kind, or null (no `push_sounds`, no entry for the kind, or an unknown slug).

- [ ] **Step 1: Write the failing tests** in `PushSoundsTest.php` (factories; Product factory with `code`/`family`; look at `tests/Feature/RenderAlertSubjectTest.php` for how bulletins and segments are built with `forCatalogCode('TO.W')`): `PushSounds::all()` has the seven slugs and every slug matches `^[a-z0-9_]+$`; `isKnown` true/false (including null and `'../x'`); `options()` shape lists six kinds and seven sounds; classification table for `ResolveAlertPushSound` with a rule whose `push_sounds` sets every kind to a distinct slug: TO.W, SV.W, FF.W, DS.W, SQ.W give the short-fuse slug; a Winter Storm Warning code (find one in the catalog seeder) gives the other-warning slug; TO.A and SV.A give convective; FF.A (or another) gives other-watch; an Advisory-family product gives advisory; a statement-family product, an SPC/WPC product and a null product give `other`; a bulletin whose segments span TO.W and an Advisory gives short-fuse (priority); a rule whose `category` is `daily_summary` or `outlook_report` gives the `other` slug; a rule with null `push_sounds`, an unset kind, or an unknown slug gives null. (The `push_sounds` attribute is added in Task 2: in this task set it with `$rule->forceFill(['push_sounds' => [...]])` on an in-memory model, no save, and add the column in Task 2.)
- [ ] **Step 2: Run, expect FAIL** (`PushSounds` class missing).
- [ ] **Step 3: Implement.** Config map in `wxnotify.php`; the enum with labels ("Short-fuse warnings", "Other warnings", "Convective watches", "Other watches", "Advisories", "Other") and one-line descriptions (for example Short-fuse: "Tornado, Severe Thunderstorm, Flash Flood and other short-fuse warnings"); classification in `ResolveAlertPushSound` with the short-fuse and convective code sets as private constants; products come from the matched segments' `product_id` when present, else the bulletin's `product_id`, via the `Product` model's `family` and `code`.
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Pint and commit** (`Push sounds: the sound list, alert kinds and classification`).

---

### Task 2: Storage and API (backend)

**Work from:** `/Users/jimmy/Documents/WxNotify/wxnotify-nextgen`

**Files:**
- Create: migration `add_push_sounds_to_alert_rules_table` (`php artisan make:migration`)
- Modify: `app/Models/AlertRule.php`, `app/Actions/Alerts/SaveAlertRule.php`, `app/Http/Requests/SaveAlertRuleRequest.php`, `app/Http/Resources/Api/V1/AlertRuleResource.php`, `app/Actions/Alerts/BuildAlertRuleOptions.php`
- Test: `tests/Feature/AlertRulePushSoundsTest.php` (new); extend `tests/Feature/ApiAlertRuleOptionsTest.php`

**Interfaces:**
- Consumes: `PushSounds`, `PushSoundKind` (Task 1).
- Produces: nullable JSON column `alert_rules.push_sounds`; `AlertRule` casts it to `array` and lists it in `#[Fillable]`. The options payload gains `data.push_sounds` = `PushSounds::options()`. The rule resource returns `push_sounds` as an object (`{}` when unset). `SaveAlertRule::attributes()` writes `push_sounds` only when the key is present in the input (an empty array or null stores null; otherwise only known kinds with known slugs are stored, nothing tier-gated).

- [ ] **Step 1: Write the failing tests:** create via the API with `push_sounds` `{short_fuse_warning: "alert", other: "bell"}` stores it and the resource returns it; update without the key keeps it; update with `{}` and with null clears it; an unknown kind (`{"loud": "alert"}`), an unknown slug (`{"other": "nope"}`), a path-like slug (`{"other": "../x"}`) and a non-array value each give 422 with an error on `push_sounds` (or `push_sounds.other`); the options payload contains `push_sounds.kinds` (six) and `push_sounds.sounds` (seven); a Free-tier user can set it (not gated); an `EditAlertRule` dashboard save of a rule with stored sounds leaves them intact (follow the existing dashboard-save tests in `AlertRuleResourceTest.php`).
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** Migration adds the column; model cast and Fillable; `SaveAlertRuleRequest::rules()` adds `'push_sounds' => ['sometimes', 'nullable', 'array']`, a rule that every key is a `PushSoundKind` value, and `'push_sounds.*' => ['nullable', Rule::in(PushSounds::slugs())]` (`ruleData()` already passes absent keys through absent); `SaveAlertRule` handles the key as described; resource and options as in Interfaces. Run the migration in the test DB the usual way (RefreshDatabase).
- [ ] **Step 4: Run, expect PASS**, plus `AlertRuleResourceTest`, `ApiAlertRulesTest`, `SaveAlertRuleTest` and the Task 1 test (now with a real column).
- [ ] **Step 5: Pint and commit** (`Push sounds: store a sound per alert kind on the rule and expose it in the API`).

---

### Task 3: Sending the sound (backend)

**Work from:** `/Users/jimmy/Documents/WxNotify/wxnotify-nextgen`

**Files:**
- Modify: `app/Support/Delivery/FcmClient.php`, `app/Support/Delivery/Transports/MobilePushTransport.php`, `app/Support/Delivery/Transports/WebhookTransport.php` (its `INTERNAL_KEYS`), `app/Jobs/DeliverWeatherAlert.php`, `app/Jobs/DeliverScheduledAlert.php`, `.ai/rules/delivery.md`
- Test: extend `tests/Feature/FcmClientTest.php`, `tests/Feature/MobilePushTransportTest.php`, `tests/Feature/DeliverWeatherAlertTest.php`, `tests/Feature/DeliverScheduledAlertTest.php` (find the real names with grep), and the webhook transport test

**Interfaces:**
- Consumes: `ResolveAlertPushSound`, `PushSounds::isKnown()` (Task 1); `alert_rules.push_sounds` (Task 2).
- Produces: `FcmClient::send(string $deviceToken, string $title, string $body, array $data = [], ?int $badge = null, ?string $sound = null): FcmResult`. With a known `$sound` the `apns.payload.aps.sound` is `"{$sound}.wav"` and `android.notification.channel_id` is `"wx_sound_{$sound}"`; with null or unknown it is exactly today's payload. Both delivery jobs add `'push_sound' => app(ResolveAlertPushSound::class)(...)` to the meta (the weather job passes the bulletin and segments; the scheduled job passes none). `MobilePushTransport::send` passes `$meta['push_sound']` to `FcmClient` only when `($meta['type'] ?? 'alert') === 'alert'`. `WebhookTransport` drops `push_sound` (add to `INTERNAL_KEYS`).

- [ ] **Step 1: Write the failing tests:** `FcmClient` iOS and Android payload assertions with a known sound (both blocks are in every message; assert both values); with null the payload equals the existing assertions (reuse the existing test as the control); with an unknown slug the payload is today's; transport: an alert meta with `push_sound` passes it, a test push (`type` = `test`) with `push_sound` passes none, and a missing key passes none; `DeliverWeatherAlert` for a rule with `{short_fuse_warning: 'alert'}` and a Tornado Warning segment sends a push whose FCM request carries `alert.wav` and `wx_sound_alert` (use the existing FCM fakes: `fcmFakeGoogle()` and the Http recording in those tests); the same rule with an Advisory product sends today's payload; a webhook channel's outgoing body never contains `push_sound` (follow the existing INTERNAL_KEYS test); a scheduled Daily Summary rule with `{other: 'bell'}` sends `bell.wav`.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** as in Interfaces; keep `ANDROID_CHANNEL` as the no-sound value. Update the `delivery.md` rule section on mobile push with the sound meta, the validation at send time and the Android channel naming (edit by hand: `record-rule` only adds sections).
- [ ] **Step 4: Run, expect PASS**, plus every push, delivery and webhook test file.
- [ ] **Step 5: Pint and commit** (`Push sounds: send the chosen sound in iOS and Android pushes`).

---

### Task 4: The plugin and the preview service (mobile)

**Work from:** `/Users/jimmy/Documents/WxNotify/wxnotify-mobile`

**Files:**
- Create: `packages/wxnotify/push-sounds/` (`composer.json`, `nativephp.json`, `resources/sounds/*.wav` moved from `resources/sounds/`, `resources/android/PushSoundsFunctions.kt`, `resources/ios/PushSoundsFunctions.swift`, `src/PushSounds.php`, `src/PushSoundsServiceProvider.php`, `src/Facades/PushSounds.php`), `app/Services/SoundPreview.php`, `tests/Fakes/RecordingSoundPreview.php`
- Modify: `composer.json` (require `wxnotify/push-sounds: ^1.0` and add its path repository beside the badge-count entry), `composer.lock` (`composer update wxnotify/push-sounds --no-scripts`), `app/Providers/NativeServiceProvider.php` (register the provider as `badge-count` is), `tests/Pest.php` (bind the fake)
- Test: `tests/Feature/SoundPreviewTest.php` (new); `tests/Unit` or Feature test for the plugin's manifest and files

**Interfaces:**
- Consumes: the seven slugs from Task 1 (the files' base names must equal them exactly).
- Produces: `Wxnotify\PushSounds\PushSounds::play(string $slug): bool` and `stop(): void` (call `nativephp_call('PushSounds.Play', json_encode(['sound' => $slug]))` / `'PushSounds.Stop'`; return false off a device or on any failure; never throw), the `PushSounds` facade, and `App\Services\SoundPreview::play(string $slug): void` (validates `^[a-z0-9_]+$`, calls the facade, catches `Throwable`, logs only the exception class). `nativephp.json`: namespace `PushSounds`, bridge functions `PushSounds.Play` and `PushSounds.Stop` (Kotlin `com.wxnotify.plugins.push_sounds.PushSoundsFunctions.*`, Swift `PushSoundsFunctions.*`), `assets` copying each `sounds/{slug}.wav` to Android `res/raw/{slug}.wav` and iOS `Resources/Sounds/{slug}.wav` (verify the iOS destination, see Step 5), and an Android `init_function` that creates the channels. Android channels: id `wx_sound_{slug}`, name `WxNotify: {Label}`, `IMPORTANCE_HIGH`, vibration and lights on like `fcm_default_channel`, sound `android.resource://{package}/raw/{slug}` with notification `AudioAttributes`; created every app start, idempotent.

- [ ] **Step 1: Write the failing tests:** `SoundPreviewTest`: `play('alert')` calls the facade once (mock the facade), a slug failing `^[a-z0-9_]+$` calls nothing, a facade that throws never throws out of `play`; a manifest test that reads `nativephp.json` and the `resources/sounds` folder and asserts every `.wav` has an `assets` entry for both platforms, every `assets` source exists, every file name matches `^[a-z0-9_]+\\.wav$`, the bridge functions exist in both native files by name, and the plugin is registered in `NativeServiceProvider`; `PushSounds::play()` returns false when `nativephp_call` is undefined.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** Scaffold from `packages/wxnotify/badge-count` (same composer.json, provider, facade, class shapes). `git mv resources/sounds/*.wav packages/wxnotify/push-sounds/resources/sounds/` (the seven files; confirm `default.wav` is not among them and leave no empty `resources/sounds`). Kotlin: create channels in the init function from a list of slugs/labels mirrored from the manifest (one place in the file, with a comment that it must match the backend list), play with `MediaPlayer`/`SoundPool` from `res/raw`, `Stop` releases it. Swift: play with `AVAudioPlayer` from the bundled file using an ambient audio session so the preview follows the silent switch like a notification would; the init function is a no-op unless Step 5's fallback is needed. Run `composer update wxnotify/push-sounds --no-scripts`, then `php artisan native:plugin:register wxnotify/push-sounds` and `php artisan native:plugin:validate` and fix what it reports.
- [ ] **Step 4: Run, expect PASS**, plus `AppBadgeTest` and the whole suite quickly (the fake binding must not disturb other tests).
- [ ] **Step 5: Device check (document the result in the task report; do not guess).** Build for the iOS simulator and a device when possible. Confirm a bundled sound plays: on iOS a push with `aps.sound` `"alert.wav"` (send one from the backend dev environment or a local APNs-style test) plays the file; if the iOS destination does not resolve, change the manifest destination to the bundle root, and if that fails too, have the iOS init function copy the bundled files into `Library/Sounds` on launch. On Android, confirm `adb shell dumpsys notification` (or Settings > Notifications) lists the seven `WxNotify: ...` channels. If no device is available, report it as unverified.
- [ ] **Step 6: Pint and commit** (`Push sounds: a plugin that bundles the sounds, makes the Android channels and plays previews`).

---

### Task 5: The wizard section (mobile)

**Work from:** `/Users/jimmy/Documents/WxNotify/wxnotify-mobile`

**Files:**
- Modify: `app/Support/RuleFormPresenter.php`, `app/NativeComponents/RuleEditor.php`, `resources/views/native/rule-editor.blade.php`
- Test: extend the existing rule editor and presenter tests (find them: `grep -l "RuleEditor::class\|RuleFormPresenter" tests -r`)

**Interfaces:**
- Consumes: the options payload `push_sounds` (`kinds`, `sounds`; Task 2), `SoundPreview` (Task 4).
- Produces: draft key `push_sounds` (`array<string, string>`: kind to slug, absent or '' = phone default); `RuleFormPresenter::showsSounds(array $draft): bool` (options have `push_sounds` and a selected channel id has type `mobile_push` in `options['channels']`), `soundSlots(array $draft): list<array{value: string, label: string, description: string}>` (all six kinds for category `weather_alerts`; exactly one slot with value `other` and label "Alert sound" otherwise), `soundChoices(): array<string, string>` (`'' => 'Phone default'` plus slug => label from the options); `draftFromRule()` reads `push_sounds` (empty when absent); `payload()` adds `'push_sounds'` (object of the non-empty picks, or `[]` to clear) only when `showsSounds($draft)`; `switchCategory()` drops kinds the new category does not offer. `RuleEditor::pickSound(string $kind, string $label): void` (ignores a kind that is not a slot and a label that is not a choice) and `RuleEditor::playSound(string $kind): void` (calls `app(SoundPreview::class)->play($slug)` for the slot's chosen slug, nothing for the default).

- [ ] **Step 1: Write the failing tests:** the section is absent when the options lack `push_sounds`, absent with only a non-`mobile_push` channel selected, present once a `mobile_push` channel is ticked; a Weather Alerts draft shows six slots and a Daily Summary draft one ("Alert sound"); `pickSound` stores the slug and "Phone default" clears it; a bogus kind or label changes nothing; saving with the section shown sends `push_sounds` with the picks, and with every pick cleared sends `[]`; saving with no phone channel selected sends no `push_sounds` key; editing a stored rule fills the draft from the API's `push_sounds`; switching category from Weather Alerts to Daily Summary leaves only `other`; `playSound` calls the fake `SoundPreview` with the chosen slug and does nothing for the default. Follow how existing tests tap controls and assert the published tree.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** In the blade, a Notification sounds block under the quiet-hours and message fields in Finishing touches, guarded by `$presenter->showsSounds($draft)`: a one-line explanation, then for each slot a label and description, a `select` (reuse the pattern of the `message_body_style` select, with `@change="pickSound(kind, $event)"`) and a Play button (`@tap="playSound(kind)"`, hidden or disabled while "Phone default"). Use theme tokens and the typed icon enums per the NativePHP skill; no raw colours.
- [ ] **Step 4: Run, expect PASS**, plus every `RuleEditor*` and `RuleFormPresenter*` test file.
- [ ] **Step 5: Pint and commit** (`Push sounds: choose and preview sounds in the alert wizard`).

---

### Task 6: Rules, device checklist and the full runs

- [ ] **Step 1:** Backend `.ai/rules/alerts.md` (`record-rule`, glob `app/Actions/Alerts/**`): one rule saying `push_sounds` is classified by `ResolveAlertPushSound`, that kinds and slugs are validated on write and at send time, that new sounds are a new slug and need an app release, and that the plugin file names must equal `config('wxnotify.push_sounds')`. Mobile `.ai/rules/` (`record-rule`, glob `packages/wxnotify/push-sounds/**` or `resources/views/native/**` as the index allows): the same coupling from the app side and that Android channels never change sound once shipped.
- [ ] **Step 2:** Full backend suite in parallel in the container per the project memory; full mobile suite (`php vendor/bin/pest --compact`); report any failure by name (the mobile `HomeScreenWeatherTest` "offline, the saved card paints..." can fail near midnight CST and is not a regression).
- [ ] **Step 3:** In the final message give the owner this device checklist: build with Bifrost; on an iPhone and an Android phone create a Weather Alerts rule with a phone channel, give Tornado Warning (short-fuse) one sound and Advisories another, and use Test product generator warnings and advisories to confirm each plays its own sound in the background and the foreground; confirm the preview plays; confirm a rule with no sounds still plays the phone default; confirm the seven `WxNotify:` channels appear in Android settings.
- [ ] **Step 4:** Commit rules in each repo (`Push sounds: project rules`).
