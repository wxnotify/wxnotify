# Tier 2 SMS and Voice Channels Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Plus and Pro subscribers can get alerts by text through their own Twilio, BulkVS or httpSMS account and by phone call through their own Twilio account, connected with the usual create → confirmation-code flow, the code going to their own number.

**Architecture:** Four `PhoneChannelType`s keep credentials, `from`, `to` (E.164) and a consent record in the encrypted `config` column, never in `apprise_url`. SMS goes through a new `ChannelTransport::Sms` → `SmsTransport`, which composes a GSM-7, two-segment message and builds the Apprise URL from `config` at send time, posting through the existing `AppriseApiClient`. Voice goes through a direct `TwilioVoiceTransport` (fixed host, one attempt, TwiML `<Say>`). The catalog's `min_tier`, stored but never enforced until now, is checked when adding a channel and again at send time; unverified phone channels get only their confirmation code.

**Tech Stack:** Laravel 12 / PHP 8.4, Filament v5, Pest + Livewire, Apprise API 2.0.0 (`twilio://`, `bulkvs://`, `httpsms://`), Twilio REST Calls API. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-10-22-tier2-sms-voice-channels-design.md` (its "Decisions" are binding; each "My call" there is the owner's to overturn).

## Global Constraints

- No new Composer packages (no libphonenumber, no Twilio SDK). Numbers are NANP only, through `App\Support\PhoneNumber`.
- Credentials, `from`, `to` and any composed Apprise URL never appear in `apprise_url`, a log line, an exception message we write, a `DeliveryResult` error, the activity log, a notification or the Livewire snapshot. Errors are fixed strings plus, for voice, the numeric HTTP status (delivery.md). Log only exception classes.
- The four catalog rows ship `min_tier = plus`, `enabled = false`; `ChannelTypeSeeder::SHIPPED` matches the migrations exactly (slug, min_tier, sort_order, enabled) and in sort order. Tests that use a phone type enable its row first with the helper `chanEnablePhoneTypes()` (Task 1, `tests/Pest.php`) and act as a Plus user (`User::factory()->create(['tier_override' => Tier::Plus])`).
- Phone types: `countsTowardFreeSlot() === false`, `supportsInlineCreate() === false`, `appSetupFields() === null`, `supportsRichContent() === false`. Connecting goes only through `BeginChannelConnection` (actions.md).
- Voice: exactly one HTTP attempt per send; never re-throw a connection error; never retry a 429 or 5xx.
- Every HTTP call in tests is faked: Apprise as `'*/notify/*'`, Twilio as `'api.twilio.com/*'`. `Http::preventStrayRequests()` is already on for Feature tests.
- Before editing read `.ai/rules/index.md` and the rule files for the paths you touch: `support.md` (app/Support/**), `delivery.md` (app/Support/Delivery/**), `actions.md`, `models.md`, `filament.md`, `feature.md`, `jobs.md` (the jobs are not edited; read it anyway for the Str::limit trap). Pest helpers are global: prefix new ones `chan…`. Wizard tests use `goToConnectChannelStep()` from `tests/Pest.php`.
- Tests: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql vendor/bin/pest --compact <paths>'`. Never `migrate:fresh`/`db:seed` against the dev DB. Pint on the host (`vendor/bin/pint --dirty --format agent`) before each commit. Commit each task on `main`, explicit paths, message ending `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Never push.

## Review Focus

1. **Number formats:** `5551234567`, `15551234567`, `+1 (555) 123-4567`, `555.123.4567` all become `+15551234567`; 7 digits, `+44…`, a leading-0/1 area code or exchange, letters and extensions are refused. `+1` vs 10-digit input for the same number is one number for the duplicate check (Tasks 1, 2).
2. **Credentials with URL-special characters** (`@ : / ? # % +` in a BulkVS username/password or an httpSMS key) are percent-encoded in the Apprise URL and round-trip through Apprise's parser (Tasks 3, 4 spikes).
3. **A rejected number / bad credentials:** Apprise answers non-200 → fixed provider text in `DeliveryLog.error_detail`, and the wizard says to check credentials, not "service unavailable" (Tasks 2, 5).
4. **429 and Apprise down:** SMS keeps today's semantics (Apprise `ConnectionException` propagates from jobs, is caught by the code/test actions); voice 429/5xx/timeout = one failed attempt, no second call (Tasks 2, 7).
5. **Downgrade Plus → Free:** the channel stays, every send is refused with the fixed tier text, it cannot be re-added, and it works again after re-upgrade; Free cannot pick it in the wizard or by API (Tasks 1, 5).
6. **Duplicate channel for the same number** on the same type is refused; on a different type it is allowed (Task 2).
7. **An unverified phone channel attached to a rule** gets no alert, only codes (Task 5).
8. **Secrets:** no auth token in the activity log, the table's edit-modal snapshot, logs or errors (Tasks 2, 6, 8).

---

## Stage 1 — SMS through Apprise

### Task 1: Tier enforcement, phone foundations and the SMS catalog rows

**Files:**
- Create: `app/Support/PhoneNumber.php`, `app/Support/PhoneConsent.php`, `app/Rules/NanpPhoneNumber.php`, `app/Support/ChannelTypes/Contracts/PhoneChannelType.php`, `app/Support/ChannelTypes/Contracts/SmsChannelType.php`, `app/Support/ChannelTypes/Concerns/IsPhoneChannel.php`, `database/migrations/2026_10_22_100001_add_sms_channel_types.php`
- Modify: `app/Enums/Tier.php`, `app/Models/User.php`, `database/seeders/ChannelTypeSeeder.php`, `tests/Pest.php`
- Test: `tests/Feature/PhoneNumberTest.php`, `tests/Feature/ChannelTierGateTest.php`

**Interfaces:**
- Consumes: `ChannelType` contract, `ChannelTypeModel` (`min_tier` cast to `Tier`), `MasksDestinationSecrets`.
- Produces:
  - `Tier::meets(Tier $minimum): bool` (order Free < Plus < Pro).
  - `User::meetsChannelTier(string $slug): bool` (catalog row's `min_tier`; no row = free). `canAddChannel()` returns false first when it fails; the rest unchanged.
  - `PhoneNumber::normalize(string $input): ?string` (`+1NXXNXXXXXX` or null), `PhoneNumber::digits(string $e164): string` (`1NXXNXXXXXX`), `PhoneNumber::mask(string $e164): string` (`•••• 4567`).
  - `NanpPhoneNumber` validation rule (message "Enter a US or Canadian number, e.g. (555) 123-4567.").
  - `PhoneConsent::VERSION = '2026-10-draft'`, `PhoneConsent::text(string $provider, bool $voice): string`.
  - `interface PhoneChannelType extends ChannelType`: `providerLabel(): string`, `failureMessage(): string`, `optOutHint(): ?string`, `confirmationHint(NotificationChannel $channel): string`, `codeNotSentHint(): string`. `interface SmsChannelType extends PhoneChannelType`: `appriseUrl(array $config): string`.
  - Trait `IsPhoneChannel`: `phoneFields(string $fromHelp): array<Component>` (TextInput `from`, TextInput `to` (tel, `NanpPhoneNumber`), Checkbox `consent` `->accepted()` labelled with `PhoneConsent::text()`); `phoneConfig(array $data): array{from: string, to: string, consent_version: string, consented_at: string}`; `buildDestination()` = `['config' => [...$this->credentialConfig($data), ...$this->phoneConfig($data)], 'label' => "{label} •••• 4567"]` (abstract `credentialConfig(array $data): array`; throws `InvalidArgumentException` on an un-normalisable number); fixed `supportsInlineCreate()`/`appSetupFields()`/`supportsRichContent()`/`countsTowardFreeSlot()`/`isAvailable()` per Global Constraints; `displaySummary()` = `PhoneNumber::mask($record->config['to'])` or `—`.
  - Catalog rows `twilio_sms` (11), `bulkvs_sms` (12), `httpsms` (13), `min_tier = plus`, `enabled = false`, in the migration and `SHIPPED`.
  - Pest helper `chanEnablePhoneTypes(): void` (sets `enabled = true` on the four phone slugs).

- [ ] **Step 1: Write the failing tests.** `PhoneNumberTest`: `it normalises common US formats to E.164` (dataset of the Review Focus formats), `it refuses numbers that are not NANP` (dataset: 7 digits, `+44 20 7946 0958`, area code starting 0/1, exchange starting 0/1, letters, `x123` extension, empty), `it gives Apprise digits only and masks to the last four`. `ChannelTierGateTest`: `Tier::meets orders free below plus below pro`, `a Free subscriber cannot add a plus-tier type even though it does not count toward the free slot` (set the `pushover` catalog row to `min_tier = plus` in the test, so the type still counts toward the slot, and also a phone slug's row once Task 2 registers one; assert `canAddChannel('pushover')` false for Free, true for Plus), `a type with no catalog row counts as free`, `the shipped free types are unchanged for a Free subscriber` (existing slot behaviour still passes).
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement** the enum method, the user methods, the support classes, the rule, the contracts, the trait (no concrete type yet), the migration (idempotent insert, `down()` deletes the three slugs, as in `2026_09_20_100004_add_webpush_channel_type.php`), the `SHIPPED` rows and the Pest helper.
- [ ] **Step 4: Run** the two new files plus `tests/Feature/ChannelTypesTest.php`, `ChannelTypeSeederTest.php`, `ChannelConnectionTest.php`, `ApiChannelsTest.php`; expect PASS. Pint.
- [ ] **Step 5: Commit** `Channels: enforce the catalog's minimum tier and add phone-number foundations`.

---

### Task 2: Twilio SMS type and the SMS transport

**Files:**
- Create: `app/Support/ChannelTypes/TwilioSmsChannelType.php`, `app/Support/Delivery/Transports/SmsTransport.php`
- Modify: `app/Enums/ChannelTransport.php` (case `Sms = 'sms'`), `app/Support/Delivery/ChannelDispatcher.php` (match arm), `app/Support/ChannelTypes/ChannelTypeRegistry.php`, `app/Actions/Channels/BeginChannelConnection.php` (duplicate-number refusal, consent activity entry), `database/factories/NotificationChannelFactory.php` (state `twilioSms(string $to = '+15555550123')`), `tests/Feature/ChannelTypesTest.php` (caption list)
- Test: `tests/Feature/TwilioSmsChannelTest.php`

**Interfaces:**
- Consumes: Task 1's contracts, trait, `PhoneNumber`; `AppriseApiClient::notify()`.
- Produces:
  - `TwilioSmsChannelType implements SmsChannelType` (slug `twilio_sms`, label "Twilio SMS", icon `heroicon-o-chat-bubble-left-ellipsis`, caption "Text number"). Fields: `account_sid` (`/^AC[0-9a-fA-F]{32}$/`), `auth_token` (password, revealable, `/^[0-9a-fA-F]{32}$/`), then `phoneFields()`; help text names the Twilio console, says the From number must be an SMS-capable Twilio number, and that US carriers require A2P 10DLC registration (or a verified toll-free number) and trial accounts can only text verified numbers. `credentialConfig()` = `{account_sid, auth_token}`. `appriseUrl($config)` = `twilio://{rawurlencode sid}:{rawurlencode token}@{PhoneNumber::digits(from)}/{PhoneNumber::digits(to)}`. `optOutHint()` = "Reply STOP to opt out."; `failureMessage()` = "Twilio did not accept the text message. Check the Account SID, auth token and From number, and that your number can receive texts."
  - `SmsTransport::send()`: resolves the type (must be `SmsChannelType`, else fixed failure), fails with "This SMS channel is missing its settings." on a blank config key, posts `notify(url: $type->appriseUrl($config), body: $body, title: $title)` (composition arrives in Task 5), returns `delivered()` or `failed($type->failureMessage())`. Lets `ConnectionException` propagate, like `AppriseTransport`.
  - `BeginChannelConnection` throws `InvalidArgumentException('You already have a {label} channel for that number.')` when the user has a channel of the same phone type whose decrypted `config['to']` equals the new one (compare after normalisation; `config` is encrypted, so filter in PHP as `existingWebPushChannel()` does); after a successful create of a phone channel it writes `activity()->performedOn($channel)->event('phone_consent')->withProperties(['channel_type' => …, 'version' => …])`.

- [ ] **Step 1: Spike (no network).** In the Apprise container run `apprise --dry-run -vv 'twilio://AC…:…@15551230001/15551230002'` and the same with a `+` in the host and with `?from=`; record which forms parse to source `+15551230001`. Confirm in `plugins/twilio.py` that a non-201/200 Twilio reply makes the plugin return False and that the Apprise API then answers non-200 (read `apprise_api` for the status it uses, likely 424). Write the result into the type's PHPDoc. If digits-only does not parse, switch `appriseUrl()` to the form that does and adjust the tests.
- [ ] **Step 2: Write the failing tests** in `TwilioSmsChannelTest`: `it builds the twilio url from config with digits-only numbers`, `it percent-encodes credentials in the url`, `it validates the account sid, auth token and both numbers` (dataset), `the consent box is required`, `completing the wizard as Plus stores config and texts the code to the subscriber's number` (assert the faked Apprise request's `urls` targets `15555550123` and `body` contains the code; channel has `apprise_url === null`), `a Free subscriber sees the tile disabled`, `the same number twice is refused but on another phone type it is allowed`, `config is encrypted, absent from the activity log, and the consent entry has no number`, `a rejected send records the fixed Twilio failure text`, `an Apprise connection error still propagates from the transport`, `the destination summary masks the number and the caption is fixed`, `the API refuses to create a twilio_sms channel` (`POST /api/v1/channels` → 422, `channel_type` invalid).
- [ ] **Step 3: Run to verify they fail.**
- [ ] **Step 4: Implement**, register the type, add the dispatcher arm, the factory state and the caption row.
- [ ] **Step 5: Run** the new file plus `ChannelTypesTest`, `ChannelDispatcherTest`, `BeginChannelConnectionTest`, `ChannelConnectionTest`, `ChannelSetupFieldsTest` (still "exactly six types can be created in the app"); expect PASS. Pint.
- [ ] **Step 6: Commit** `Channels: Twilio SMS through Apprise`.

---

### Task 3: BulkVS SMS type

**Files:**
- Create: `app/Support/ChannelTypes/BulkVsSmsChannelType.php`
- Modify: `ChannelTypeRegistry.php`, `NotificationChannelFactory.php` (state `bulkVsSms()`), `tests/Feature/ChannelTypesTest.php` (caption)
- Test: `tests/Feature/BulkVsSmsChannelTest.php`

**Interfaces:**
- Consumes: Task 1–2 (`SmsChannelType`, `IsPhoneChannel`, `SmsTransport`).
- Produces: `BulkVsSmsChannelType implements SmsChannelType` (slug `bulkvs_sms`, label "BulkVS SMS", caption "Text number"). Fields `api_username` (required, max 255), `api_password` (password, revealable, required, max 255) + `phoneFields()`; help points to BulkVS portal → API credentials and says the From number must be an SMS-enabled BulkVS number. `appriseUrl()` = `bulkvs://{rawurlencode user}:{rawurlencode password}@{digits from}/{digits to}`. `optOutHint()` from the spike (null unless BulkVS documents STOP handling); `failureMessage()` = "BulkVS did not accept the text message. Check the API username and password and the From number."

- [ ] **Step 1: Spike (no network).** `apprise --dry-run -vv` with a username containing `@` and a password containing `:/?#%+`, percent-encoded; confirm Apprise decodes them to the original values (`plugins/bulkvs.py` `parse_url` / `unquote`). Check BulkVS's public docs for what the portal calls the credentials and whether it handles STOP; record both in the PHPDoc (the opt-out answer feeds `optOutHint()` and the owner's legal review).
- [ ] **Step 2: Write the failing tests:** `it builds the bulkvs url with encoded credentials` (dataset of special characters), `it validates the fields`, `completing the wizard as Plus texts the code through bulkvs`, `a rejected send records the fixed BulkVS failure text`, `the destination summary masks the number`.
- [ ] **Step 3: Run to verify they fail.** **Step 4: Implement.** **Step 5: Run** the new file plus `ChannelTypesTest`; PASS; Pint.
- [ ] **Step 6: Commit** `Channels: BulkVS SMS through Apprise`.

---

### Task 4: httpSMS type

**Files:**
- Create: `app/Support/ChannelTypes/HttpSmsChannelType.php`
- Modify: `ChannelTypeRegistry.php`, `NotificationChannelFactory.php` (state `httpSms()`), `tests/Feature/ChannelTypesTest.php` (caption)
- Test: `tests/Feature/HttpSmsChannelTest.php`

**Interfaces:**
- Consumes: Task 1–2.
- Produces: `HttpSmsChannelType implements SmsChannelType` (slug `httpsms`, label "httpSMS (your Android phone)", icon `heroicon-o-device-phone-mobile`, caption "Text number"). Fields `api_key` (password, revealable, required, max 255) + `phoneFields()` where `from` is "the number of the Android phone running the httpSMS app" and `to` the number that receives alerts (may be the same phone). `appriseUrl()` = `httpsms://{rawurlencode key}@{digits from}/{digits to}`. `optOutHint()` null; `failureMessage()` = "httpSMS did not accept the text message. Check the API key, that the httpSMS app is running on your phone, and the From number."

- [ ] **Step 1: Spike (no network).** `apprise --dry-run -vv` with a key containing `_`, `-` and a `%`-encoded character; confirm the key round-trips and whether from = to is accepted by the plugin.
- [ ] **Step 2: Write the failing tests:** `it builds the httpsms url with an encoded key`, `from and to may be the same phone`, `completing the wizard as Plus texts the code through httpsms`, `a rejected send records the fixed httpSMS failure text`.
- [ ] **Step 3: Run to verify they fail.** **Step 4: Implement.** **Step 5: Run**; PASS; Pint.
- [ ] **Step 6: Commit** `Channels: httpSMS through Apprise`.

---

### Task 5: SMS message format, send-time gates and wizard wording

**Files:**
- Create: `app/Support/Delivery/SmsMessage.php`
- Modify: `app/Support/Delivery/Transports/SmsTransport.php`, `app/Support/Delivery/ChannelDispatcher.php`, `app/Filament/Dashboard/Pages/ConnectChannel.php` (Confirm step helper text, code-not-sent body)
- Test: `tests/Feature/SmsMessageTest.php`, `tests/Feature/ChannelDispatcherTest.php` (extend), `tests/Feature/TwilioSmsChannelTest.php` (extend)

**Interfaces:**
- Consumes: `PhoneChannelType`, `User::meetsChannelTier()`, `MessagePrefix` (already applied to the title before the transport).
- Produces:
  - `SmsMessage::MAX_LENGTH = 306`, `SmsMessage::compose(string $body, ?string $title, array $meta, ?string $optOutHint = null): string` per the spec's "SMS message format" (GSM-7 folding, `{title}\n{summary}\n{link}`, title ≤ 100, link from `meta.url` never cut and dropped only when > 120 chars, summary cut at a word boundary with `...`; `meta.type === 'confirmation'` → the fixed code text from `meta.code` plus the hint; `'test'` → the fixed test text, using the body's quoted label). Never calls `Str::limit(..., preserveWords: true)` (jobs.md).
  - `SmsTransport` sends `SmsMessage::compose(...)` as the body with `title: null` (the title is already in the text).
  - `ChannelDispatcher::send()` before choosing the transport: owner below the type's `min_tier` → `failed('This channel needs the Plus plan or higher.')`; `PhoneChannelType` and unverified and `meta.type !== 'confirmation'` → `failed('This number has not been confirmed yet.')`. Both before any HTTP.
  - `ConnectChannel` Confirm helper text = `$type->confirmationHint($channel)` for a phone type (SMS: "We just texted a 6-digit code to •••• 4567."); the `ChannelCodeNotSent` notification body = `$type->codeNotSentHint()` for a phone type ("We couldn't send the code. Check your Twilio credentials and both numbers, then try again."), unchanged for others.

- [ ] **Step 1: Write the failing tests.** `SmsMessageTest`: `it folds to the GSM-7 basic set` (curly quotes, an emoji, `[x]`, `{}`, `€`, accented letters), `it never exceeds two segments`, `the link is kept whole and the summary is cut at a word with an ellipsis`, `a very long link is dropped rather than cut`, `no link leaves more summary`, `the confirmation text carries the code and the opt-out hint when given`, `line breaks in the body are collapsed`. `ChannelDispatcherTest`: `a downgraded subscriber's phone channel is refused at send time without calling Apprise`, `it works again after re-upgrade`, `an unverified phone channel gets its confirmation code but no alert or test`, `non-phone channels are unaffected by the unverified rule`. `TwilioSmsChannelTest`: `an alert from DeliverWeatherAlert arrives as the composed SMS and logs success`, `a failed alert logs the fixed Twilio text, never the url or number`, `the wizard says where the code went and, on failure, to check the credentials`.
- [ ] **Step 2: Run to verify they fail.** **Step 3: Implement.**
- [ ] **Step 4: Run** the three files plus `DeliverWeatherAlertTest`, `DeliverScheduledAlertTest`, `ChannelConnectionTest`, `ChannelActionsTest`; PASS; Pint.
- [ ] **Step 5: Commit** `SMS: two-segment GSM-7 messages, tier and confirmation gates at send time`.

---

### Task 6: Catalog drift, factory reset and secret-exposure checks

**Files:**
- Modify: `tests/Feature/ChannelTypeSeederTest.php`, `tests/Feature/FactoryResetActionsTest.php`, `tests/Feature/NotificationChannelResourceTest.php`, `.ai/rules/delivery.md` and `.ai/rules/support.md` via `record-rule`
- Test: the files above

**Interfaces:**
- Consumes: Tasks 1–5.
- Produces: no new code (fix only what the tests find). Rules recorded: (delivery) "Phone channels keep credentials and numbers in `config`; SMS goes through `SmsTransport` → Apprise with the URL built at send time; the dispatcher enforces `min_tier` and refuses unverified phone channels"; (support) "Numbers go through `PhoneNumber` (NANP only); phone types are not creatable inline or in the app and need a consent checkbox".

- [ ] **Step 1: Write the failing tests:** `the migrations and the seeder agree on every shipped row` (compare slug, min_tier, sort_order, enabled for every row, replacing the slug-only check), `the phone types ship disabled and plus-only`, `factory reset restores an enabled or re-tiered phone type to disabled plus` (in `FactoryResetActionsTest`), `the edit modal snapshot of a twilio sms channel holds no auth token or number` (`json_encode($component->snapshot)` does not contain the token or `5555550123`), `the edit form has no reveal action for a phone channel`.
- [ ] **Step 2: Run; fix anything they expose.** **Step 3: Record the two rules with `record-rule`.**
- [ ] **Step 4: Run** the three files plus `ResetToFactoryDefaultsTest`, `RevealChannelUrlTest`; PASS; Pint.
- [ ] **Step 5: Commit** `Channels: catalog drift, reset and secret checks for phone channels`.

---

## Stage 2 — Twilio voice call

### Task 7: Twilio voice transport and script

**Files:**
- Create: `app/Support/Delivery/Transports/TwilioVoiceTransport.php`, `app/Support/Delivery/VoiceScript.php`
- Modify: `app/Enums/ChannelTransport.php` (case `TwilioVoice = 'twilio_voice'`), `app/Support/Delivery/ChannelDispatcher.php`
- Test: `tests/Feature/TwilioVoiceTransportTest.php`, `tests/Feature/VoiceScriptTest.php`

**Interfaces:**
- Consumes: a `NotificationChannel` whose `config` has `account_sid`, `auth_token`, `from`, `to` (E.164); `PhoneChannelType::failureMessage()` is not used here (voice errors are mapped).
- Produces:
  - `VoiceScript::compose(string $body, ?string $title, array $meta): string` → TwiML per the spec (two `<Say language="en-US">`, a 1 s `<Pause>`, "Again." before the repeat; confirmation digits spoken one by one; XML-escaped ASCII; no link; < 4000 chars).
  - `TwilioVoiceTransport::ENDPOINT = 'https://api.twilio.com/2010-04-01/Accounts/%s/Calls.json'`, `CONNECT_TIMEOUT = 5`, `TIMEOUT = 10`. `send()`: blank config or SID not `^AC[0-9a-f]{32}$` (case-insensitive) → fixed failure before any HTTP; `Http::asForm()->withBasicAuth(sid, token)->connectTimeout(5)->timeout(10)->withoutRedirecting()->post(sprintf(ENDPOINT, sid), ['To' => to, 'From' => from, 'Twiml' => VoiceScript::compose(...)])`; 201 → `delivered()`; `ConnectionException` → `failed('Twilio did not answer in time; a call may still have been placed.')` (caught, logged as class only, never re-thrown); otherwise `failed(self::describe(int $status, ?int $twilioCode))`. `describe()` is a fixed map: 401/20003 credentials refused; 21211 / 21214 number cannot be called; 21210 / 21212 From is not a voice number on this account; 21219 trial account can only call verified numbers; 21215 geographic permissions; 429 Twilio is rate limiting this account; ≥ 500 Twilio had an error; anything else "Twilio refused the call (HTTP {status})." The Twilio `code` is read from the JSON body only as an integer; no body text is ever kept.

- [ ] **Step 1: Spike.** Read Twilio's Calls resource docs (WebFetch `https://www.twilio.com/docs/voice/api/call-resource`) and its error dictionary for the codes above; confirm form-encoded `To`/`From`/`Twiml`, the 201 response, the JSON error shape `{code, message, more_info, status}`, and that `Twiml` is limited to 4000 characters. Adjust the map and the tests to what the docs say; note anything that differs in the class PHPDoc.
- [ ] **Step 2: Write the failing tests.** `VoiceScriptTest`: `an alert is said twice with a pause`, `the confirmation code is read digit by digit twice`, `XML special characters are escaped`, `the script stays under the twilio limit for a 1500-char body`, `no url is spoken`. `TwilioVoiceTransportTest`: `it posts to the account's calls endpoint with basic auth and form fields` (assert URL, auth header, `To`/`From`/`Twiml`), `a 201 is a delivered call`, `known twilio error codes map to fixed text` (dataset), `a 429 and a 500 are one attempt and a failure` (`Http::assertSentCount(1)`), `a timeout is a failure, not an exception, and nothing is retried`, `a malformed account sid never reaches the network`, `no error text contains the sid, token, number or response body`.
- [ ] **Step 3: Run to verify they fail.** **Step 4: Implement**, add the dispatcher arm.
- [ ] **Step 5: Run** the two files plus `ChannelDispatcherTest`; PASS; Pint.
- [ ] **Step 6: Commit** `Voice: a one-attempt Twilio call transport with a spoken script`.

---

### Task 8: Twilio voice type, catalog row and confirmation by call

**Files:**
- Create: `app/Support/ChannelTypes/TwilioVoiceChannelType.php`, `database/migrations/2026_10_22_100002_add_twilio_voice_channel_type.php`
- Modify: `ChannelTypeRegistry.php`, `database/seeders/ChannelTypeSeeder.php`, `NotificationChannelFactory.php` (state `twilioVoice()`), `tests/Feature/ChannelTypesTest.php` (caption), `.ai/rules/delivery.md` via `record-rule`
- Test: `tests/Feature/TwilioVoiceChannelTest.php`, `tests/Feature/ChannelTypeSeederTest.php` (already full-row)

**Interfaces:**
- Consumes: Task 1 (`PhoneChannelType`, `IsPhoneChannel`), Task 7 transport, Task 5 dispatcher gates.
- Produces: `TwilioVoiceChannelType implements PhoneChannelType` (slug `twilio_voice`, label "Twilio voice call", icon `heroicon-o-phone`, caption "Call number", `transport()` = `TwilioVoice`). Same credential fields and regexes as Twilio SMS (shared constants on `TwilioSmsChannelType`, not copied), `phoneFields()` with consent text for calls; help says the From number must be a voice-capable Twilio number and that each alert is a billed call. `optOutHint()` null. `confirmationHint()` = "We're calling •••• 4567 now. Answer and listen for the 6-digit code." `codeNotSentHint()` = "We couldn't place the call. Check your Twilio credentials and both numbers, then try again." Catalog row `twilio_voice`, sort 14, `plus`, `enabled = false`, in the migration and `SHIPPED`. Rule recorded: "Voice calls go through `TwilioVoiceTransport`: one attempt, connection errors caught and reported, never retried."

- [ ] **Step 1: Write the failing tests:** `completing the wizard as Plus places one call that speaks the code` (fake Twilio 201; assert one request whose `Twiml` contains the spaced digits and `To` is the subscriber's number), `a failed call removes the new channel and says to check the credentials`, `resending the code places exactly one new call`, `the same number may have both a twilio sms and a twilio voice channel`, `a verified voice channel on a rule is called once for a matching alert and the log row has no secret`, `a Free subscriber cannot pick voice and a downgraded one is refused at send time`, `the migrations and the seeder agree` (existing full-row test now includes `twilio_voice`).
- [ ] **Step 2: Run to verify they fail.** **Step 3: Implement**, register, migrate rows, record the rule.
- [ ] **Step 4: Run** the new file plus `ChannelTypesTest`, `ChannelTypeSeederTest`, `FactoryResetActionsTest`, `ChannelConnectionTest`, `ApiChannelWritesTest` (resend through the API places one call); PASS; Pint.
- [ ] **Step 5: Commit** `Channels: Twilio voice calls`.

---

### Task 9: Final verification

- [ ] Run every file touched above in one run, then ask the owner to run the full suite (`vendor/bin/pest --parallel --processes=6` in the app container).
- [ ] `grep -rn "auth_token\|account_sid\|api_password\|api_key" app | grep -i "log\|activity\|error"` finds nothing that writes a secret.
- [ ] Report the spike findings (Tasks 2–4, 7) and the owner list below.

## On-device / manual checks owed (owner, real accounts; not automatable)

1. Twilio SMS from the owner's own account and number to the owner's phone: code arrives, a test arrives, an alert arrives as two segments or fewer with a working link; note any "Sent from your Twilio trial account" prefix.
2. Reply STOP to the Twilio number: the next send fails with the fixed text in the delivery log; reply START restores it.
3. A wrong auth token and an unverified trial destination: the wizard shows the "check your credentials" message and no channel is left behind.
4. BulkVS SMS from the owner's BulkVS number (if the owner has an account): code and alert arrive; record STOP behaviour.
5. httpSMS with the owner's Android phone running the app: code and alert arrive; check behaviour when the phone is offline (Apprise result and delivery log).
6. Twilio voice: the code call is understandable at normal speaking speed; an alert call says the title and summary twice; voicemail pickup plays the message; the subscriber hears no URL or garbled abbreviations (note any NWS all-caps wording that reads badly).
7. Downgrade the owner's test account from Plus to Free and back: channels stop with the tier text and resume.
8. Apprise container stopped: the wizard says the code could not be sent; a queued alert job fails without a log row (existing behaviour, confirm unchanged).
