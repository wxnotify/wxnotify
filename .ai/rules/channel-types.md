---
paths:
  - 'app/Support/ChannelTypes/**'
---

# Channel Types

## Guarded chat servers: allow-list refusal, shared helpers
Types with a subscriber-supplied host (Mattermost, Rocket.Chat, Matrix) use `ChatServerAddress` (the only place server normalisation lives), the `IsGuardedChatServer` concern and the `SafeChatServerUrl` rule; `ChatServerGuard` (built on `WebhookUrlGuard::hostRisk()`) allows https only unless self-host private networks are allowed, no single-label hosts, ports 443 or >= 1024. Apprise DECODES URL paths/userinfo (`%2F` becomes a separator, `%3F`/`%23` a raw `?`/`#`), so rawurlencode does not protect: every id/token/path/room component is REFUSED by an allow-list at parse time and again in `appriseUrl()` (a tampered stored value yields an empty authority, so the transport refuses). The Apprise authority must be exactly the host of `serverUrl()` (derive both from the same stored string); `serverUrl()` is null when no server is stored; `destinationKey()` is normalised inside each type (BeginChannelConnection compares the raw string). URL patterns use the `D` modifier and refuse whitespace/control characters. Matrix: access token only, always encoded; a missing alias makes Apprise create a private room on the bot's own homeserver (cannot be disabled).

## ntfy custom server: same guard, vetted at send
ntfy's optional Server field uses `SafeChatServerUrl` (so https only, public hosts only; http and private targets only on self-host with `allow_private_networks`). It has no Messaging transport: `AppriseTransport` re-checks `NtfyChannelType::serverUrlFrom($channel->apprise_url)` with `ChatServerGuard` on every send and fails with the fixed `NtfyChannelType::SERVER_REFUSED`. A saved channel with a now-refused host is kept, never deleted. The default server (`ntfy://topic`) is never checked.

## Twilio voice channel type: ships disabled, shared code bucket
`twilio_voice` is a phone type (`placesCalls()` true, shared credential fields from `TwilioSmsChannelType`, consent text for calls) shipped DISABLED + plus-only (sort 14) until the owner's legal review. Every confirmation-code resend places a billed call, so the phone code throttle is load-bearing (5/hour per user+number, 10/hour per user); SMS and voice channels on the same number share the per-number bucket on purpose (it protects the person behind the number). An SMS and a voice channel may coexist on one number; a second voice channel on it is refused.

## ntfy custom server: guarded at save and send, cannot be edited
A custom ntfy "Server" (including `ntfy.sh` typed in the field) is guarded like the chat servers: `SafeChatServerUrl` at save (dashboard form and API descriptor) and `AppriseTransport` re-vets it with `ChatServerGuard` on EVERY send (a DNS lookup each time; ntfy channels only, other Apprise types are never checked). `buildAppriseUrl` takes scheme and authority from `ChatServerAddress::parts()` so the stored URL is the exact normalised host the guard judges. The server CANNOT be edited (the dashboard edit modal and `PUT /channels/{id}` change only the label), so a refused channel is fixed by removing it and adding it again; the fixed texts are `SERVER_REFUSED` (policy) and `SERVER_UNRESOLVED` (a failed name lookup, not a policy statement). Existing cloud channels with http, private, odd-port or single-label servers fail at send until re-added.
