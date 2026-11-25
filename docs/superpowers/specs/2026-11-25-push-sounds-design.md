# Push notification sounds per alert kind: design

## Why

Every push from WxNotify plays the phone's default notification sound, so a Tornado Warning sounds the same as a Winter Weather Advisory. A subscriber who wants to tell them apart by ear, or hear something special for the alerts that matter most, cannot. They should be able to give each kind of alert its own sound, per alert rule, the same way on iPhone and Android.

## Decisions (agreed in conversation, 2026-11-25)

- Sounds are chosen **per alert rule**, for six **kinds** of alert: **Short-fuse warnings** (Tornado, Severe Thunderstorm, Flash Flood, etc.), **Other warnings**, **Convective watches** (Tornado and Severe Thunderstorm Watches only), **Other watches**, **Advisories**, and **Other**.
- The subscriber picks them in the mobile app's alert wizard, in a new section of the **Finishing touches** step. The section appears only when at least one chosen channel is a phone (mobile push) channel.
- Not choosing leaves **the phone's own default sound** for that kind (and for the whole rule if nothing is chosen). WxNotify does not replace the default.
- iOS and Android behave the same. The sound files are bundled in the app (`resources/sounds`, seven WAVs today: alert, back, bell, correct, fail, harp, notification; more later).
- Approach A: the **server picks the sound** per push; a **new plugin** ships the files and prepares Android. The wizard has a **tap-to-hear preview**.

## Goals

- Each rule can map each of the six kinds to one of the bundled sounds, or to "Phone default".
- A push for that rule plays the chosen sound on both platforms, in the background and in the foreground.
- A subscriber can hear a sound before choosing it.
- Rules that never set a sound send exactly what they send today.

## Non-goals

Editing sounds on the web dashboard (it must only never wipe them), per-product or per-location sounds, account-wide default sounds, user-uploaded sounds, a sound for test pushes or the Daily Summary beyond the "Other" kind, volume or vibration controls, and any change to email, webhook or other channels.

## Anti-goals (a failure even if it technically works)

- A rule with no sounds must behave byte-for-byte as today (same payload, same Android channel).
- A saved sound name must never reach the push payload unless it is in the server's list of known sounds.
- A web-dashboard save of a rule must never clear its sounds.
- A phone whose app lacks a sound the server names must still alert audibly (default sound), never silently.

## Constraints

- Two repositories. Backend: `wxnotify-nextgen` (Laravel 13, Filament v5, Pest). App: `wxnotify-mobile` (NativePHP v4, built by Bifrost from GitHub, which checks out the repo, so every file the build needs must be tracked in git).
- **iOS** plays a remote push's sound from a file name in the push (`aps.sound`); the file must be in the app's main bundle (or its `Library/Sounds`), in a supported format (linear PCM WAV is) and under 30 seconds. The seven files qualify (16-bit PCM, 1.3 to 3.5 seconds, about 3.7 MB together).
- **Android 8+** plays the sound of the push's **notification channel**; the sound is fixed when the channel is created, and a push can only name a channel. The only channel today is `fcm_default_channel` (created by the Firebase plugin). A channel id that does not exist falls back to a default-importance channel.
- A NativePHP plugin's manifest can copy assets into the iOS and Android builds (`assets`: source relative to the plugin's own `resources/` folder to a destination: `res/raw/...` for Android, `Resources/...` for iOS) and can run an `init_function` at app start. Sources are resolved inside the plugin, so the WAVs live in the plugin, not in the app's `resources/sounds`.
- Follow `.ai/rules` in both repos for the paths touched (alerts, delivery, models, native components, tests).

## Approaches considered

- **A. Server picks; a plugin ships the files and makes the Android channels (chosen).** Sound names travel in the rule; the push names a file (iOS) or a channel (Android).
- **B. One Android channel per alert kind, recreated when the sound changes.** Rejected: Android keeps deleted channels visible in system settings and the sound is immutable.
- **C. iOS only.** Rejected: both platforms must behave the same.
- **D. The app plays the sound itself from the push's data.** Rejected: needs local notifications (just removed) and unreliable background handling on iOS.

## Design

### The sounds

One list is the source of truth on the backend: `config('wxnotify.push_sounds')`, a map of slug to label (slug = the file's base name, lower case, letters, digits and underscores only, which Android requires for `res/raw`). The app bundles a file of the same name for each. A **sound's file is never replaced once shipped** (an Android channel keeps the sound it was created with): a changed sound is a new slug.

### The six kinds

A `PushSoundKind` enum (backed by strings, with a label and a one-line description for the wizard): `short_fuse_warning`, `other_warning`, `convective_watch`, `other_watch`, `advisory`, `other`.

An action classifies an alert from its product (family and catalog code):
- **Short-fuse warning:** family Warning with code `TO.W`, `SV.W`, `FF.W`, `DS.W` or `SQ.W`.
- **Other warning:** any other Warning-family product.
- **Convective watch:** family Watch with code `TO.A` or `SV.A`.
- **Other watch:** any other Watch-family product.
- **Advisory:** family Advisory.
- **Other:** everything else: statements, SPC/WPC products, forecast discussions, informational, administrative and aviation products, and the scheduled alerts (Daily Summary, Threshold, Lightning, Outlook Report).

A bulletin whose matched segments span products (a delivery names every matched segment) takes the **highest-priority kind among them**, in the order above. The short-fuse code set is one constant, easy to change.

### The rule

`alert_rules.push_sounds`: nullable JSON, `{kind: slug}`. A missing kind means the phone's default. Only Weather Alerts rules can produce the first five kinds; every other category produces only `other`, so the wizard shows all six slots for a Weather Alerts rule and a single "Alert sound" slot for any other category (the `other` value).

### The API

- `GET /api/v1/alert-rule-options` gains `push_sounds`: the kinds (`value`, `label`, `description`) and the sounds (`value`, `label`). A backend without it simply hides the section in the app.
- The alert rule resource returns `push_sounds` (an object, empty when unset).
- Create and update accept `push_sounds`: an object whose keys are known kinds and whose values are a known slug or null. **An absent key keeps the stored value; an empty object or null clears it** (the same rule as per-product subjects). `SaveAlertRule` and `SaveAlertRuleRequest` own this, so the dashboard form, which never sends the key, never clears it.

### Sending

`DeliverWeatherAlert` and `DeliverScheduledAlert` resolve the sound (rule's map, then the alert's kind) and add `push_sound` (a slug or absent) to the delivery meta. `MobilePushTransport` passes it to `FcmClient::send(...)` as a new optional argument only for alert pushes (`type` = alert), after checking it against the sound list (an unknown value is dropped). `FcmClient` then sets:
- iOS: `apns.payload.aps.sound` = `{slug}.wav` instead of `default`;
- Android: `android.notification.channel_id` = `wx_sound_{slug}` instead of `fcm_default_channel`.
With no sound the payload is exactly what it is today. Test pushes never carry a sound.

### The plugin (mobile repo)

A new `wxnotify/push-sounds` plugin (beside `badge-count`, registered in `NativeServiceProvider`):
- **Assets:** the WAVs move into the plugin's `resources/sounds/`; the manifest copies each to iOS `Resources/...` and Android `res/raw/{slug}.wav`.
- **Android init:** an `init_function` creates one channel per sound, `wx_sound_{slug}`, named "WxNotify: {Label}" (visible in system settings), importance high, vibration and lights as `fcm_default_channel`, the sound set to that raw resource with notification audio attributes. It runs at every app start, so the channels exist before any push.
- **iOS:** the builder verifies on a device that a push's `aps.sound` finds the copied file; if the bundle location does not resolve, the iOS init copies the bundled files into `Library/Sounds` at launch.
- **Preview:** a bridge function `PushSounds.Play(slug)` (AVAudioPlayer on iOS, MediaPlayer or SoundPool on Android) with a PHP facade, wrapped by an app service that never throws (the app's plugin-wrapper rule) and has a test fake bound in `tests/Pest.php`.

### The wizard (mobile repo)

In `rule-editor`, under Finishing touches, a **Notification sounds** section shown only when `$options['push_sounds']` exists and at least one selected channel has type `mobile_push`. One select per slot (options: "Phone default" plus every sound) with a play button that previews the current choice (disabled for "Phone default"). It writes `draft.push_sounds` through the existing draft and presenter code; the save payload sends `push_sounds` only when the section was shown (so a rule edited with no phone channel selected keeps its stored sounds).

### Deploy and compatibility

Backend first (migration, options, send path), then a new app build. An old app against the new backend never shows the section and never sets a sound. A new app against an old backend gets no `push_sounds` in the options, so it hides the section. A phone running an app that lacks a sound a rule names plays its default (iOS: missing file; Android: the fallback channel).

## Testing

Backend (Pest): classification of every kind including multi-segment priority and the scheduled categories; `FcmClient` payloads for iOS and Android with and without a sound and with an unknown slug (dropped); the transport passes a sound only for alerts, never for a test push; options payload; validation (unknown kind, unknown slug, absent key keeps, empty clears); the dashboard save keeps `push_sounds`. App (Pest, Native test harness): the section appears only with a mobile channel and the options key; payload contents and the keep-when-hidden rule; the preview service with its fake; one slot for a non-Weather-Alerts category. Native code (Kotlin, Swift) and the iOS bundle location are checked on a real device and the simulator, not by tests.

## Left to the builder

File names and the exact plugin layout, sound labels (default: the file name, title-cased), Swift and Kotlin player details (including whether the preview follows the iOS silent switch: it should behave like a notification would), the wizard's exact layout, and where the classification action lives under `app/Actions`.

## Calls made without being asked (open to change)

- Rules store sounds per kind; non-Weather-Alerts categories show one slot.
- The highest-priority kind wins for a multi-product bulletin.
- Short-fuse means TO.W, SV.W, FF.W, DS.W and SQ.W; statements fall under Other.
- A sound file is never changed once shipped; a change is a new slug.
- Adding a sound needs an app release, and older apps play their default for it.
- Test pushes, and any push that is not an alert, keep the phone's default sound.
- Hidden-section saves keep stored sounds.
