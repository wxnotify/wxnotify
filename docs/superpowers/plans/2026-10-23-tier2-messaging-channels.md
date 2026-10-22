# Tier 2 Messaging Channels Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Plus and Pro subscribers can receive alerts in Google Chat, Mattermost, Rocket.Chat and Matrix through their own webhook or bot, and on WhatsApp through their own Meta WhatsApp Business account (approved template). Each is connected with the usual create → confirmation-code flow, and the code is posted to the destination.

**Architecture:** A new `ByoChannelType` contract holds the fixed failure and confirmation hints. `PhoneChannelType` now extends it, and so does a new `MessagingChannelType`, whose types keep credentials in encrypted `config` and build their Apprise URL at send time. `ChannelTransport::Messaging` → `MessagingTransport` re-checks a subscriber-supplied server through the new `ChatServerGuard` (built on `WebhookUrlGuard::hostRisk()`), composes with `MessagingMessage` and posts through `AppriseApiClient`. The dispatcher's unverified gate, the duplicate-destination check and the code throttle widen to the new types. The SMS types, `SmsTransport` and their tests are not changed.

**Tech Stack:** Laravel 12 / PHP 8.4, Filament v5, Pest + Livewire, Apprise API 2.0.0 (`gchat://`, `mmost[s]://`, `rocket[s]://`, `matrix[s]://`, `whatsapp://`). No new dependencies.

**Spec:** `docs/superpowers/specs/2026-10-23-tier2-messaging-channels-design.md`. Its decisions (numbered 1–21) are binding; each "My call" there is the owner's to overturn.

## Global Constraints

- **COMMIT TRAILER: every commit message ends with exactly `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.** It says **Sonnet**, not Opus. Twice in the SMS batch an implementer wrote Opus 5.5 and the commit had to be redone. Check `git log -1` after committing.
- Commit each task on `main` with explicit paths. Never push. Never `migrate:fresh`/`db:seed` against the dev DB.
- **Before each commit, run the full suite once in Docker:** `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --parallel --processes=6'`. One test is known to be flaky: `ApiAlertRulesTest > reading rules does not query per product`. Re-run it alone before treating it as a failure. For targeted runs, use `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql vendor/bin/pest --compact <paths>'`. Run Pint on the host (`vendor/bin/pint --dirty --format agent`).
- **Never edit `.ai/rules` in a task.** The controller records rules (see Task 9). Do read `.ai/rules/index.md` and the rule files for the paths you touch: delivery.md, support.md, actions.md, filament.md, feature.md, models.md, api.md.
- **Test files:** top-level constants and helpers are `CHAN_`/`chan`-prefixed. Pest runs them in one global namespace in parallel, so unprefixed names collide. Shared helpers go in `tests/Pest.php`. Wizard tests use `goToConnectChannelStep()` and act as `User::factory()->create(['tier_override' => Tier::Plus])`.
- **Validate form fields through the fields' own rules** (mount the wizard and fill the field), not only through rule classes. Filament's `->tel()` and `->url()` add their own rules: use `->type('tel')` for numbers. For the pasted URL fields, use plain `TextInput`s with our own rules, so that `->url()` cannot refuse a valid IPv6 or sub-path URL first.
- **Secrets:** tokens, keys, webhook URLs and numbers never reach `apprise_url`, a log line, an exception message we write, a `DeliveryResult` error, the activity log, a notification or the Livewire snapshot. Channel-send paths log only `$e::class`, never `getMessage()`. Each type's test file has (a) a secret-exposure test (raw `config` is encrypted in the DB row; the activity log, the `DeliveryLog.error_detail` after a 424, and the edit-modal snapshot never contain the credential) and (b) a percent-encoding test whose dataset puts `/ @ : ? # % space` and `é` into every user-supplied URL component the type's validation allows. Show that the encoded URL round-trips: assert the exact `urls` string sent to the faked Apprise.
- Every HTTP call is faked (Apprise as `'*/notify/*'`; `Http::preventStrayRequests()` is on). DNS is faked by binding `ChatServerGuard` with a resolver closure through `chanFakeChatDns()` (Task 3). Spikes run only against the local Apprise container (`docker compose exec -T apprise python -I -`), with `Apprise.instantiate()` or a monkeypatched `requests.post`, and never against a real service.
- The five types: `countsTowardFreeSlot() === false`, `supportsInlineCreate() === false`, `appSetupFields() === null`, `supportsRichContent() === false`, `apprise_url` null. Creation goes only through `BeginChannelConnection`. Every catalog row is `min_tier = plus`. `whatsapp` is disabled; the other four are enabled.

## Review Focus

1. **Hosts that resolve privately.** `chat.example.com` resolving to `10.0.0.5`, `127.0.0.1`, `169.254.169.254`, `100.64.0.1` or `::ffff:7f00:1`; a name that is public at save time and private at send time (refused at send, no HTTP); single-label `apprise`/`pgsql`; `.internal`/`.local` (Tasks 3, 4).
2. **IPv6 literals** `[2001:db8::1]` (documentation range, refused), a public `[2606:4700::1]` (allowed if Apprise builds a working request; Task 3 spike), and brackets kept in the Apprise URL (Tasks 3–6).
3. **Userinfo and `@` in a pasted URL** (`https://user:pw@chat.example.com/hooks/…`, `https://chat.example.com@10.0.0.5/…`): refused, never parsed into the host (Task 3).
4. **Mattermost trailing slashes and sub-paths:** `…/hooks/{token}/`, `/mm/hooks/…`, `//hooks`, `%2F` in a segment, and a query string (Task 4).
5. **Rocket.Chat** webhook URL vs a pasted user/token or REST URL (refused with guidance), and a sub-path (refused) (Task 5).
6. **Matrix room** alias vs ID vs `#` encoding (`%23` in the path), a room v12 ID without a server part, a `@user` target (refused), discovery off, no password form (Task 6).
7. **Google Chat** URL pasted whole vs only the key, a `%3D` in the token decoded once, never twice, another host (refused), `threadKey` ignored (Task 1).
8. **WhatsApp numbers:** `+52 1 55 1234 5678`, `0052…`, `+44 7…`, ten-digit US, `+1` with a 0/1 exchange (refused), a 7-digit string; the template name/language not matching (fixed failure text); a newline in the body never reaching the parameter (Tasks 7, 8).
9. **Downgrade Plus → Free:** the channel is kept, every send is refused with `TIER_REFUSAL`, and it cannot be added again (Tasks 1, 9).
10. **Apprise down / 424:** a 424 gives the fixed type text; a `ConnectionException` propagates from the transport and is caught by the code path (Tasks 1, 4).
11. **Duplicate destination** on the same type is refused; Back/Next with the same destination refreshes the pending channel; another type is allowed (Task 2).
12. **Unconfirmed channel on a rule** gets no alert and no test, only its code (Task 1).

---

### Task 1: Messaging foundations, the catalog rows and Google Chat

**Files:**
- Create: `app/Support/ChannelTypes/Contracts/ByoChannelType.php`, `app/Support/ChannelTypes/Contracts/MessagingChannelType.php`, `app/Support/ChannelTypes/Concerns/IsMessagingChannel.php`, `app/Support/Delivery/MessagingMessage.php`, `app/Support/Delivery/Transports/MessagingTransport.php`, `app/Support/ChannelTypes/GoogleChatChannelType.php`, `database/migrations/2026_10_23_100001_add_messaging_channel_types.php`
- Modify: `app/Support/ChannelTypes/Contracts/PhoneChannelType.php` (extends `ByoChannelType`; remove the three methods it now inherits, signatures unchanged), `app/Enums/ChannelTransport.php` (`Messaging = 'messaging'`), `app/Support/Delivery/ChannelDispatcher.php`, `app/Filament/Dashboard/Pages/ConnectChannel.php`, `app/Filament/Dashboard/Resources/NotificationChannels/Tables/NotificationChannelsTable.php`, `app/Support/ChannelTypes/ChannelTypeRegistry.php`, `database/seeders/ChannelTypeSeeder.php`, `database/factories/NotificationChannelFactory.php` (state `googleChat()`), `tests/Pest.php` (`chanEnableWhatsApp()`), `tests/Feature/ChannelTypesTest.php` (caption row)
- Test: `tests/Feature/GoogleChatChannelTest.php`, `tests/Feature/MessagingMessageTest.php`, `tests/Feature/ChannelDispatcherTest.php` (extend)

**Interfaces:**
- Consumes: `AppriseApiClient::notify()`, `ChannelDispatcher::refusal()`, `User::meetsChannelTier()`, `MasksDestinationSecrets`.
- Produces:
  - `interface ByoChannelType extends ChannelType`: `failureMessage(): string`, `confirmationHint(NotificationChannel $channel): string`, `codeNotSentHint(): string`.
  - `interface MessagingChannelType extends ByoChannelType`:
    - `requiredConfigKeys(): list<string>`
    - `appriseUrl(array $config): string`
    - `serverUrl(array $config): ?string` (the base URL to vet, null for a fixed host)
    - `destinationKey(array $config): string`
    - `compose(string $body, ?string $title, array $meta): string`
  - Trait `IsMessagingChannel`:
    - fixed flags as listed in Global Constraints; `transport()` = `Messaging`;
    - abstract `configFromForm(array $data): array`;
    - `buildDestination()` = `['config' => configFromForm(), 'label' => label()]`;
    - `maxLength(): int` (default 4000);
    - `compose()` = `MessagingMessage::chat($body, $title, $meta, $this->maxLength())`;
    - `guardedQuery(array $extra = []): string`, which returns `redirect=no&cto=5&rto=10` plus the extras, built with `http_build_query`.
  - `MessagingMessage::chat(string $body, ?string $title, array $meta, int $maxLength): string` (spec "Message format"; confirmation and test return the body unchanged). `MessagingMessage::whatsapp(string $body, ?string $title, array $meta): string` (one line, `WHATSAPP_MAX_LENGTH = 900`, fixed confirmation and test texts). Neither uses `Str::limit(..., preserveWords: true)` (jobs.md).
  - `MessagingTransport::send()`:
    1. If the type is not a `MessagingChannelType`, fail with "This channel cannot send messages.".
    2. If a required key is blank, fail with "This channel is missing its settings.".
    3. Otherwise `notify(url: appriseUrl(), body: compose(), title: null)`, then `delivered()` or `failed(failureMessage())`. A `ConnectionException` propagates. Task 3 adds the server check; Google Chat has no server.
  - `ChannelDispatcher::refusal()`: the unverified check uses `ByoChannelType` instead of `PhoneChannelType`. The message is chosen per type: phone types keep "This number has not been confirmed yet."; others get "This channel has not been confirmed yet.".
  - `ConnectChannel` and `NotificationChannelsTable`: each `instanceof PhoneChannelType` used for `confirmationHint`/`codeNotSentHint` becomes `ByoChannelType`.
  - `GoogleChatChannelType implements MessagingChannelType` (slug `google_chat`, icon `fab-google`, caption "Google Chat space"):
    - One field, `webhook_url` (password, revealable, required, max 2048), parsed per spec decision 18 into `{space, key, token}`.
    - Validation message: "Paste the whole webhook URL from Google Chat: Apps & integrations → Webhooks."
    - `appriseUrl()` = `gchat://{enc space}/{enc key}/{enc token}`. `serverUrl()` = null. `destinationKey()` = `space`.
    - Summary: `spaces/••••{last4 of space}`.
    - `confirmationHint()` = "We just posted a 6-digit code to your Google Chat space."
    - `codeNotSentHint()` = "We couldn't post to that Google Chat webhook. Check the URL, and that the webhook still exists in the space."
    - `failureMessage()` = "Google Chat did not accept the message. Check the webhook URL and that the webhook still exists in the space."
  - Migration and `SHIPPED` rows: `whatsapp` 15 disabled, then `google_chat` 16, `mattermost` 17, `rocketchat` 18, `matrix` 19 enabled; all plus. Rows without a registered class are filtered out by `forPicker()` until their task. Pest helper `chanEnableWhatsApp(): void`.

- [ ] **Step 1: Spike (no network).** Instantiate `gchat://` with a token containing `=`, `%3D` and `-_`, and record which encoding round-trips: `rawurlencode` of the decoded token. Monkeypatch `requests.post` in the container and send a body containing `*bold*`, `_x_` and `<b>` through `mmosts://`, `gchat://` and `rockets://` with the default text input format. Record whether Apprise escapes or passes markdown through. If it mangles NWS text such as `*` bullets, put the finding in `MessagingMessage`'s PHPDoc and keep plain text. Write all results into the class PHPDocs.
- [ ] **Step 2: Write the failing tests.**
  - `GoogleChatChannelTest`:
    - `it parses a pasted webhook url into space, key and token`
    - `it refuses another host, http, a missing key or token, and a partial url` (dataset)
    - `it percent-encodes every component in the apprise url` (dataset)
    - `completing the wizard as Plus stores config only and posts the code` (assert the faked `urls` and the code in `body`; `apprise_url === null`)
    - `a Free subscriber sees the tile disabled and is refused on a forced nextStep`
    - `a 424 records the fixed Google Chat text`
    - `an Apprise connection error propagates from the transport`
    - `the secret never appears in config, the activity log, the failure detail or the edit modal`
    - `the summary masks the space and the caption is fixed`
    - `the API refuses to create a google_chat channel`
  - `MessagingMessageTest`:
    - `the link is kept whole and the body is cut at a word to the type's limit`
    - `unicode survives`
    - `confirmation and test bodies pass through for chat`
    - `whatsapp text is one line of at most 900 characters with no tab, newline or five-space run` (seeded property test over 300 inputs)
    - `whatsapp confirmation and test texts are fixed`
  - `ChannelDispatcherTest`:
    - `an unconfirmed messaging channel gets its code but no alert or test`
    - `phone channels keep their unconfirmed message`
    - `a downgraded subscriber's google chat channel is refused without calling Apprise`
- [ ] **Step 3: Run to verify they fail.**
- [ ] **Step 4: Implement.** The migration is idempotent and `down()` deletes the five slugs, following `2026_10_22_100001_add_sms_channel_types.php`.
- [ ] **Step 5: Run** the three files plus `ChannelTypesTest`, `ChannelTypeSeederTest`, `ConnectChannelAvailabilityTest`, `PhoneConfirmationThrottleTest`, `TwilioSmsChannelTest`, `NotificationChannelResourceTest`, `ChannelSetupFieldsTest` ("exactly six types can be created in the app" must still hold); expect PASS. Full suite. Pint.
- [ ] **Step 6: Commit** `Channels: messaging foundations and Google Chat` (trailer: Sonnet 5.5).

---

### Task 2: Duplicate destinations and the code throttle for messaging types

**Files:**
- Modify: `app/Actions/Channels/BeginChannelConnection.php`, `app/Actions/Channels/DispatchChannelConfirmationCode.php`, `app/Support/ChannelTypes/Contracts/MessagingChannelType.php` + `GoogleChatChannelType.php` (`destinationNoun()`)
- Test: `tests/Feature/MessagingChannelConnectionTest.php`

**Interfaces:**
- Consumes: `MessagingChannelType::destinationKey()`, Task 1's Google Chat type and factory state.
- Produces:
  - `BeginChannelConnection::refuseDuplicateDestination()`: for a non-phone `MessagingChannelType`, it loads the user's channels of the slug, decrypts them, and compares `destinationKey()`. On a match it throws `InvalidArgumentException("You already have a {label} channel for that {noun}.")`, where the noun comes from a new `MessagingChannelType::destinationNoun(): string` ("space", "channel", "room"). Phone types keep `refuseDuplicateNumber()` unchanged. Pending reuse (`reusablePendingChannel()`) already covers non-phone types: same user, same type, unverified.
  - `DispatchChannelConfirmationCode`:
    - `CHAT_CODES_PER_DESTINATION_PER_HOUR = 5`, `CHAT_CODES_PER_USER_PER_HOUR = 10`
    - `chatDestinationLimitKey(int $userId, string $destinationKey): string` (`chat-code-destination:{user}:{sha256}`), `chatUserLimitKey(int $userId): string`
    - `refuseWhenDestinationThrottled(int $userId, string $destinationKey): void`
    - The spend step branches: a `PhoneChannelType` is handled exactly as today; any other `MessagingChannelType` spends the chat buckets; everything else is not limited.
  - `BeginChannelConnection` calls `refuseWhenDestinationThrottled()` before storing a messaging channel, the same way it calls the phone check.

- [ ] **Step 1: Write the failing tests:**
  - `the same google chat space twice is refused and the first channel is untouched`
  - `back then next with the same space refreshes the pending channel` (one channel, 2 sends)
  - `a sixth code to one space in an hour is refused without writing or sending`
  - `an eleventh code across spaces is refused`
  - `the chat limits do not touch the phone buckets` (twilio_sms still gets its 5)
  - `resend in the table is throttled with the same notice`
  - `a throttled send leaves the stored code and the wrong-code counter alone`
- [ ] **Step 2: Run to verify they fail.** **Step 3: Implement.**
- [ ] **Step 4: Run** the new file plus `PhoneConfirmationThrottleTest`, `ConnectChannelAvailabilityTest`, `BeginChannelConnectionTest`, `ApiChannelWritesTest`; PASS. Full suite. Pint.
- [ ] **Step 5: Commit** `Channels: one channel per messaging destination and a code throttle` (trailer: Sonnet 5.5).

---

### Task 3: ChatServerGuard and its save-time rule

**Files:**
- Create: `app/Support/Delivery/ChatServerGuard.php`, `app/Rules/SafeChatServerUrl.php`
- Modify: `app/Support/Delivery/Transports/MessagingTransport.php` (wire the send-time check into the Task 1 seam), `tests/Pest.php` (`chanFakeChatDns(array $hostToAddresses): void`, which binds `ChatServerGuard` with a resolver closure; unknown hosts resolve to `[]`)
- Test: `tests/Feature/ChatServerGuardTest.php`

**Interfaces:**
- Consumes: `WebhookUrlGuard::hostRisk(string $host, ?array $resolved)`, `WebhookUrlGuard::resolve()`, `PlainAsciiHost`, `config('wxnotify.mode')`, `config('wxnotify.webhooks.allow_private_networks')`.
- Produces:
  - `final class ChatServerGuard`: `__construct(?Closure $resolver = null)`, which builds its own `WebhookUrlGuard($resolver)`. `violation(string $url): ?string` applies the spec decision 13 rules in this order: parse → scheme → userinfo → query/fragment → `PlainAsciiHost` → single-label → port → `hostRisk()`. The path is not judged; each type validates its own. `privateNetworksAllowed(): bool` is public, so the types can allow http.
  - Fixed messages (constants), which never echo the URL:
    - "Enter the server's full https:// address."
    - "The address must start with https://."
    - "The address must not contain a username or password."
    - "Remove the ?query or #fragment from the address."
    - "Enter the server's full domain name, e.g. chat.example.com."
    - "That port is not allowed; use 443 or a port above 1023."
    - "That server could not be found."
    - "That server is on a private or reserved network, which is not allowed."
    - plus `PlainAsciiHost::MESSAGE`.
  - `SafeChatServerUrl implements ValidationRule`, which fails with the guard's message (resolved through `app()`).
  - `MessagingTransport` returns `failed($violation)` before any HTTP when `serverUrl()` is non-null and the guard refuses.

- [ ] **Step 1: Spike (no network).**
  - Monkeypatch `requests.post` in the container and send a `mmosts://[2606:4700::1]:8443/{token}` and a `matrixs://tok@[2606:4700::1]/%21r%3Aex.org?discovery=no` notify. Record the request URL Apprise builds. If either breaks the brackets, refuse IPv6 literals with "Enter the server's domain name rather than an IP address." and note this in the PHPDoc.
  - Confirm that `redirect=no` gives `allow_redirects=False` in the `requests.post` call.
- [ ] **Step 2: Write the failing tests** (datasets, all through `chanFakeChatDns`):
  - `it allows a public https host on 443 and above 1023`
  - `it refuses private, loopback, link-local, metadata, cgnat and mapped addresses` (literal and resolved)
  - `it refuses single-label and blocked names before any lookup` (the resolver records no call)
  - `it refuses userinfo, a query, a fragment, http, ports below 1024 except 443, and port 0 or above 65535`
  - `it refuses a host that does not resolve`
  - `it refuses non-ascii and percent-encoded hosts`
  - `self-host with private networks allowed accepts a lan host and http on 80 or above 1023`
  - `the cloud ignores the private-network flag`
  - `no message repeats any part of the url`
  - `the rule fails with the guard's message`
- [ ] **Step 3: Run to verify they fail.** **Step 4: Implement.**
- [ ] **Step 5: Run** the new file plus `WebhookUrlGuardTest`, `TraccarUrlGuardTest`, `GoogleChatChannelTest`; PASS. Full suite. Pint.
- [ ] **Step 6: Commit** `Delivery: a server guard for subscriber-hosted chat servers` (trailer: Sonnet 5.5).

---

### Task 4: Mattermost

**Files:**
- Create: `app/Support/ChannelTypes/MattermostChannelType.php`
- Modify: `ChannelTypeRegistry.php`, `NotificationChannelFactory.php` (state `mattermost()`), `tests/Feature/ChannelTypesTest.php` (caption)
- Test: `tests/Feature/MattermostChannelTest.php`

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces: `MattermostChannelType implements MessagingChannelType` (slug `mattermost`, icon `fab-mattermost`, caption "Mattermost server", noun "channel").
  - **Field:** `webhook_url` (password, revealable, required, max 2048, `SafeChatServerUrl`, plus the type's own path rule from spec decision 17). Help: "In Mattermost: Integrations → Incoming Webhooks → Add. Paste the whole URL. The server must be reachable from the internet."
  - **Stored config:** `{server: scheme://host[:port], path, token}`.
  - **URL:** `appriseUrl()` = `mmost{s}://{host}[:{port}]{/enc segment...}/{enc token}?{guardedQuery(['image' => 'no'])}`. The `s` suffix is chosen from the scheme, and the bracketed IPv6 host is kept as is.
  - **Lookups:** `serverUrl()` = `server`; `destinationKey()` = `host:port/path/token`; the summary is the host.
  - **Texts:**
    - `confirmationHint()` = "We just posted a 6-digit code to your Mattermost channel."
    - `codeNotSentHint()` = "We couldn't post to that Mattermost webhook. Check the URL and that the server is reachable from the internet."
    - `failureMessage()` = "Mattermost did not accept the message. Check the webhook URL and that the channel still exists."

- [ ] **Step 1: Spike (no network).** Monkeypatch `requests.post` and record the request URL for:
  - a sub-path `/mm`
  - a trailing slash
  - port 8065
  - a segment holding `%20` or `é` (encoded)
  
  Confirm it is `https://host:port/mm/hooks/{token}`. Confirm `retry=0` is the default (no second post).
- [ ] **Step 2: Write the failing tests:**
  - `it parses webhook urls with and without a sub-path, port and trailing slash` (dataset)
  - `it refuses a url without /hooks/, a bad token, a query, a double slash and userinfo` (dataset)
  - `it percent-encodes sub-path segments in the apprise url`
  - `completing the wizard as Plus posts the code to the webhook`
  - `a server that resolves privately is refused at save time`
  - `a server that turns private after saving is refused at send time without calling Apprise`
  - `a 424 records the fixed Mattermost text`
  - `an Apprise connection error during the wizard says the code could not be sent and leaves no channel`
  - `the same webhook twice is refused`
  - the secret-exposure test
  - `the API refuses to create a mattermost channel`
- [ ] **Step 3: Run to verify they fail.** **Step 4: Implement**, register, add the factory state and caption.
- [ ] **Step 5: Run** the new file plus `ChannelTypesTest`, `ChannelDispatcherTest`; PASS. Full suite. Pint.
- [ ] **Step 6: Commit** `Channels: Mattermost through Apprise` (trailer: Sonnet 5.5).

---

### Task 5: Rocket.Chat

**Files:**
- Create: `app/Support/ChannelTypes/RocketChatChannelType.php`
- Modify: `ChannelTypeRegistry.php`, `NotificationChannelFactory.php` (state `rocketChat()`), `tests/Feature/ChannelTypesTest.php`
- Test: `tests/Feature/RocketChatChannelTest.php`

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces: `RocketChatChannelType implements MessagingChannelType` (slug `rocketchat`, label "Rocket.Chat", icon `fab-rocketchat`, caption "Rocket.Chat server", noun "channel").
  - **Field:** `webhook_url` (password, revealable), validated against `https://host[:port]/hooks/{id}/{token}`, both `[A-Za-z0-9]+`.
  - **Refusals:**
    - A sub-path is refused with "Rocket.Chat under a sub-path isn't supported yet."
    - A `/api/v1/...` URL is refused with "Paste the incoming webhook URL (Administration → Integrations → New → Incoming), not an API address."
  - **Stored config:** `{server, webhook_id, webhook_token}`. `maxLength()` = 1000.
  - **URL:** `appriseUrl()` = `rocket{s}://{enc id}%2F{enc token}@{host}[:{port}]?{guardedQuery(['avatar' => 'no'])}`. `destinationKey()` = `host:port/webhook_id`.
  - **Texts:** the hints and failure text follow the Mattermost wording, with "Rocket.Chat" in place of the name.

- [ ] **Step 1: Spike (no network).** Monkeypatch `requests.post` and confirm:
  - webhook mode is chosen (no `api/v1/login` call);
  - the request is `https://host:port/hooks/{id}/{token}`;
  - a body over 1000 characters is sent whole, not split (overflow `upstream`).
- [ ] **Step 2: Write the failing tests:**
  - `it parses the incoming webhook url` (with and without a port)
  - `it refuses a sub-path, an api url, a pasted user token and a bad id` (dataset)
  - `it encodes the webhook in the userinfo with %2F`
  - `completing the wizard as Plus posts the code`
  - `messages are cut to 1000 characters with the link kept`
  - `private at save and at send time is refused`
  - `a 424 records the fixed Rocket.Chat text`
  - `the same webhook twice is refused`
  - the secret-exposure test
  - API refusal
- [ ] **Step 3: Run to verify they fail.** **Step 4: Implement.**
- [ ] **Step 5: Run** the new file plus `ChannelTypesTest`; PASS. Full suite. Pint.
- [ ] **Step 6: Commit** `Channels: Rocket.Chat through Apprise` (trailer: Sonnet 5.5).

---

### Task 6: Matrix

**Files:**
- Create: `app/Support/ChannelTypes/MatrixChannelType.php`
- Modify: `ChannelTypeRegistry.php`, `NotificationChannelFactory.php` (state `matrix()`), `tests/Feature/ChannelTypesTest.php`
- Test: `tests/Feature/MatrixChannelTest.php`

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces: `MatrixChannelType implements MessagingChannelType` (slug `matrix`, icon `fab-matrix`, caption "Matrix room", noun "room").
  - **Fields:**
    - `homeserver` (required, `SafeChatServerUrl`, no path other than `/`)
    - `access_token` (password, revealable, required, max 512, `^[A-Za-z0-9._~+/=-]+$`)
    - `room` (required; either `^![A-Za-z0-9._=-]+(:[A-Za-z0-9.-]+)?$` or `^#[A-Za-z0-9._=-]+:[A-Za-z0-9.-]+$`)
    
    Refusal message for the room: "Enter a room ID (!abc123:example.org) or alias (#weather:example.org)."
  - **Help text** (spec decision 16):
    - matrix.org users enter `https://matrix-client.matrix.org`;
    - invite the bot account to the room and join it;
    - use an unencrypted room: WxNotify does not encrypt messages, and in an encrypted room they show as unencrypted or may be hidden;
    - where to find a bot access token.
  - **Stored config:** `{server, token, room}`.
  - **URL:** `appriseUrl()` = `matrix{s}://{enc token}@{host}[:{port}]/{enc room}?{guardedQuery(['discovery' => 'no', 'e2ee' => 'no', 'mode' => 'off', 'image' => 'no'])}`. There is never a user part, so Apprise never logs in or registers.
  - **Lookups:** `destinationKey()` = `host:port/room`; the summary is the room.
  - **Texts:**
    - `confirmationHint()` = "We just posted a 6-digit code to your Matrix room."
    - `codeNotSentHint()` = "We couldn't post to that room. Check the homeserver address and access token, and that the bot has joined the room."

- [ ] **Step 1: Spike (no network).** Monkeypatch `requests.get` and `requests.post` in the container, then send to an ID room and an alias room, and record the call sequence. Expected:
  - `whoami`, `join`, then `send` (or `PUT`);
  - no `/login` and no `/register` call;
  - no `.well-known` request;
  - the room arrives decoded as `!x:ex.org` / `#w:ex.org`;
  - `e2ee=no` sends plaintext.
  
  Record whether `join` on a missing alias attempts `createRoom`. If it does, say so in the help text ("the room must already exist").
- [ ] **Step 2: Write the failing tests:**
  - `it accepts room ids with and without a server part and aliases`, and `it refuses @user, a bare name and unicode` (datasets)
  - `the room's ! # : are percent-encoded in the path`
  - `the url never carries a user and always sets discovery=no and e2ee=no`
  - `a homeserver with a path or query is refused`
  - `completing the wizard as Plus posts the code`
  - `private at save and at send time is refused`
  - `a 424 records the fixed Matrix text`
  - `the same room twice is refused even with another token`
  - the secret-exposure test (the token)
  - API refusal
- [ ] **Step 3: Run to verify they fail.** **Step 4: Implement.**
- [ ] **Step 5: Run** the new file plus `ChannelTypesTest`; PASS. Full suite. Pint.
- [ ] **Step 6: Commit** `Channels: Matrix through Apprise` (trailer: Sonnet 5.5).

---

### Task 7: International numbers and phone-trait hooks (no SMS behaviour change)

**Files:**
- Create: `app/Rules/InternationalPhoneNumber.php`
- Modify: `app/Support/PhoneNumber.php`, `app/Support/PhoneConsent.php`, `app/Support/ChannelTypes/Concerns/IsPhoneChannel.php`, `app/Actions/Channels/BeginChannelConnection.php` (number comparisons), `app/Actions/Channels/DispatchChannelConfirmationCode.php` (`limitsFor()` normalisation)
- Test: `tests/Feature/PhoneNumberTest.php` (extend)

**Interfaces:**
- Consumes: the existing `PhoneNumber`, `IsPhoneChannel` and throttle.
- Produces:
  - `PhoneNumber::normalizeInternational(string $input): ?string`, per spec decision 19. It is NANP first; otherwise it strips formatting, maps `00` to `+`, and requires `^\+[2-9]\d{7,14}$`.
  - `PhoneNumber::INTERNATIONAL_INVALID_MESSAGE`, and the rule `InternationalPhoneNumber`.
  - `PhoneConsent::whatsappText(): string` (spec decision 21; same `VERSION`).
  - `IsPhoneChannel`:
    - `protected usesFromNumber(): bool` (default true; when false, `phoneFields()` leaves out `from` and `phoneConfig()` stores none);
    - `protected normalizeNumber(string $input): ?string` (default `PhoneNumber::normalize`);
    - `protected toNumberRule(): ValidationRule` (default `NanpPhoneNumber`);
    - `protected consentText(): string` (default `PhoneConsent::text(...)`).
  - Duplicate, reuse and throttle comparisons call `normalizeInternational()`. For stored NANP E.164 the results are identical, so the keys and outcomes do not change.

- [ ] **Step 1: Write the failing tests:**
  - `it normalises international whatsapp numbers` (dataset: `+52 1 55 1234 5678`, `0052 55 1234 5678`, `+44 7700 900123`, `(555) 234-5678`)
  - `it refuses numbers that are too short, too long, start +0, or are +1 but not nanp`
  - `normalizeInternational agrees with normalize for every nanp input` (reuse the existing NANP dataset)
  - `the phone throttle key for a nanp number is unchanged`
- [ ] **Step 2: Run to verify they fail.** **Step 3: Implement.**
- [ ] **Step 4: Run** `PhoneNumberTest`, `TwilioSmsChannelTest`, `BulkVsSmsChannelTest`, `HttpSmsChannelTest`, `PhoneConfirmationThrottleTest`, `ConnectChannelAvailabilityTest`, all **unchanged and green**. Do not edit these existing tests. Full suite. Pint.
- [ ] **Step 5: Commit** `Phone: international numbers and trait hooks for WhatsApp` (trailer: Sonnet 5.5).

---

### Task 8: WhatsApp (Meta Cloud API template)

**Files:**
- Create: `app/Support/ChannelTypes/WhatsAppChannelType.php`
- Modify: `ChannelTypeRegistry.php`, `NotificationChannelFactory.php` (state `whatsApp(string $to = '+525512345678')`), `tests/Feature/ChannelTypesTest.php`
- Test: `tests/Feature/WhatsAppChannelTest.php`

**Interfaces:**
- Consumes: Tasks 1, 2, 7; `MessagingMessage::whatsapp()`.
- Produces: `WhatsAppChannelType implements PhoneChannelType, MessagingChannelType`, using `IsPhoneChannel` with `usesFromNumber() = false`, the international rule and `whatsappText()` (slug `whatsapp`, icon `fab-whatsapp`, caption "WhatsApp number", provider "Meta WhatsApp", `optOutHint()` null). `transport()` = `Messaging`; resolve the trait conflict explicitly.
  - **Fields:**
    - `access_token` (password, revealable, `^[A-Za-z0-9]+$`, max 1024)
    - `phone_number_id` (`^\d{6,20}$`)
    - `template` (`^[a-z0-9_]{1,512}$`)
    - `template_language` (Select: `en_US`, `en`, `es`, `es_MX`; default `en_US`)
    - `to` (international)
    - consent
  - **Help text** (spec decision 3):
    - the exact suggested Utility template, its one variable not at the start or end;
    - approval is needed first;
    - Meta bills each message;
    - a test number reaches only its registered recipients;
    - business-initiated messages need the template.
  - **Lookups:** `requiredConfigKeys()` = the five keys. `serverUrl()` = null. `destinationKey()` = `to`.
  - **URL:** `appriseUrl()` = `whatsapp://{enc template}:{enc token}@{enc phone_number_id}/{digits of to}?lang={enc language}&:1=body`.
  - **Message:** `compose()` = `MessagingMessage::whatsapp()`.
  - **Texts:**
    - `confirmationHint()` = "We just sent a 6-digit code to •••• 5678 on WhatsApp."
    - `codeNotSentHint()` = "We couldn't send the WhatsApp message. Check the access token, phone number ID, and that the template name and language match an approved template with one variable."
    - `failureMessage()` = spec decision 11.

- [ ] **Step 1: Spike (no network).** Monkeypatch `requests.post` and send through `whatsapp://wx_alert:EAAB…@123456789012/525512345678?lang=es_MX&:1=body`. Record:
  - the JSON payload: `type: template`, `template.name`, `language.code`, `components[0].parameters[0].text` = the body;
  - that `+` handling gives `to` = `+525512345678`;
  - that a non-200 reply makes Apprise return False (→ 424).
  
  Also record in the PHPDoc why the Twilio route was rejected: in `plugins/twilio.py`, WhatsApp mode posts a free-form `Body` only. Confirm `:1=body` and `%3A1=body` both parse, and use the literal form.
- [ ] **Step 2: Write the failing tests:**
  - `it builds the template url with encoded parts` (dataset)
  - `it validates token, phone number id, template name, language and number through the fields`
  - `the consent box is required and recorded without the number`
  - `completing the wizard as Plus sends the code as the template parameter on one line`
  - `a disabled whatsapp row is not offered and a forced nextStep is refused`
  - `an unconfirmed whatsapp channel gets no alert`
  - `an alert arrives as one line within 900 characters with the link`
  - `a 424 records the fixed WhatsApp text`
  - `the same number twice is refused; Back and Next with it refreshes`
  - `codes are limited by the phone buckets`
  - the secret-exposure test (token and number)
  - `the API refuses to create a whatsapp channel`
- [ ] **Step 3: Run to verify they fail.** **Step 4: Implement.**
- [ ] **Step 5: Run** the new file plus `ChannelTypesTest`, `PhoneConfirmationThrottleTest`, `ChannelDispatcherTest`; PASS. Full suite. Pint.
- [ ] **Step 6: Commit** `Channels: WhatsApp through Meta's Cloud API template` (trailer: Sonnet 5.5).

---

### Task 9: Whole-batch checks and final verification

**Files:**
- Modify: `tests/Feature/ChannelTypeSeederTest.php`, `tests/Feature/FactoryResetActionsTest.php`, `tests/Feature/NotificationChannelResourceTest.php`
- Test: `tests/Feature/MessagingChannelsBatchTest.php`

**Interfaces:**
- Consumes: Tasks 1–8. Produces no new code; fix only what the tests find.

- [ ] **Step 1: Write the failing tests:**
  - `the messaging rows ship plus-only with whatsapp disabled and the four chat types enabled` (SHIPPED and a migration `up()` re-run). The full-row drift test already covers agreement.
  - `factory reset restores the five rows` (enabled/re-tiered → shipped state)
  - Edit-modal snapshot dataset for the five types: no token, key, webhook URL, number, `config` or `apprise_url`; the reveal action cannot be called.
  - In `MessagingChannelsBatchTest`:
    - `a Plus subscriber connects each of the five types through the wizard` (dataset; WhatsApp enabled with `chanEnableWhatsApp()`)
    - `a Free subscriber sees all five disabled in the picker`
    - `none of the five is creatable in the app, the API or the alert form's inline create`
    - `a downgraded subscriber's five channels are kept, refused with the tier text and resume on re-upgrade`
    - `the app's channel list shows them generically` (`GET /api/v1/channels`: `type_label`, `destination`, `destination_label`, no secret)
- [ ] **Step 2: Run; fix anything they expose.**
- [ ] **Step 3: Run** every file touched in this batch in one run, then the full suite. Pint. Check that `grep -rn "getMessage()" app/Support/Delivery app/Actions/Channels` returns nothing new.
- [ ] **Step 4: Commit** `Channels: batch checks for the messaging channels` (trailer: Sonnet 5.5).
- [ ] **Step 5: Report** the spike findings (Tasks 1, 3–6, 8) and the rules below to the controller.

**Rules to record (the controller records these with `record-rule`; implementers do not edit `.ai/rules`):**
- **delivery.md:**
  - Messaging channels keep credentials in `config`; `MessagingTransport` builds the Apprise URL at send time and re-checks `serverUrl()` with `ChatServerGuard` before any HTTP.
  - Every guarded URL carries `redirect=no&cto=5&rto=10`; Matrix also carries `discovery=no&e2ee=no` and never a user part.
  - **Exception to the pinning rule:** Apprise does its own lookup, so these sends cannot be pinned. DNS rebinding is a documented residual, mitigated at the network level, not in code.
- **delivery.md:** The dispatcher refuses every unverified `ByoChannelType` (phone and messaging) except its confirmation code.
- **actions.md:** Messaging code sends are limited in `DispatchChannelConfirmationCode` in their own buckets (5/hour per user+destination key, 10/hour per user). Phone buckets are unchanged. Duplicate destinations are refused by `destinationKey()`.
- **support.md:** WhatsApp numbers go through `PhoneNumber::normalizeInternational()`; SMS stays NANP-only. Number comparisons in the actions use `normalizeInternational()`.
- **support.md:** `ChatServerGuard` is the guard for subscriber-hosted chat servers (Mattermost, Rocket.Chat, Matrix): https only unless self-host private networks are allowed, no single-label hosts, built on `WebhookUrlGuard::hostRisk()`.

## On-device / manual checks owed (owner, own accounts; not automatable)

1. **Google Chat:** a webhook in the owner's Workspace space. The code, a test and an alert arrive; the link works; check how markdown-like NWS text renders.
2. **Mattermost:** a self-hosted or cloud server, once at the root and once under a sub-path. Code, test and alert arrive. Disable the webhook, and the next send shows the fixed text.
3. **Rocket.Chat:** an incoming-webhook integration. Code and alert arrive, and the 1000-character cut is readable.
4. **Matrix:**
   - On matrix.org with `https://matrix-client.matrix.org` and a bot token, in an unencrypted room: code and alert arrive.
   - In an encrypted room, record what Element shows.
   - With an alias for a room that does not exist, record whether a room was created.
5. **WhatsApp:** a Meta developer app with a test number and the owner's phone as a registered recipient.
   - Submit the suggested Utility template in English and Spanish, and record approval or reclassification.
   - The code and an alert arrive as the template.
   - A wrong template name or language gives the fixed text.
   - A message to a non-registered number fails.
6. **SSRF in staging:** a Mattermost URL whose DNS answers with `127.0.0.1` and one answering `169.254.169.254` are refused at save. Point a test domain to a public IP, save the channel, then switch the record to `10.x`: the send is refused and the Apprise container log shows no outbound attempt.
7. **Downgrade** the owner's test account from Plus to Free and back: all five stop with the tier text and resume.
8. **Apprise container stopped:** the wizard says the code could not be sent and leaves no channel behind.
