---
paths:
  - 'app/Support/ChannelTypes/**'
---

# Channel Types

## Guarded chat servers: allow-list refusal, shared helpers
Types with a subscriber-supplied host (Mattermost, Rocket.Chat, Matrix) use `ChatServerAddress` (the only place server normalisation lives), the `IsGuardedChatServer` concern and the `SafeChatServerUrl` rule; `ChatServerGuard` (built on `WebhookUrlGuard::hostRisk()`) allows https only unless self-host private networks are allowed, no single-label hosts, ports 443 or >= 1024. Apprise DECODES URL paths/userinfo (`%2F` becomes a separator, `%3F`/`%23` a raw `?`/`#`), so rawurlencode does not protect: every id/token/path/room component is REFUSED by an allow-list at parse time and again in `appriseUrl()` (a tampered stored value yields an empty authority, so the transport refuses). The Apprise authority must be exactly the host of `serverUrl()` (derive both from the same stored string); `serverUrl()` is null when no server is stored; `destinationKey()` is normalised inside each type (BeginChannelConnection compares the raw string). URL patterns use the `D` modifier and refuse whitespace/control characters. Matrix: access token only, always encoded; a missing alias makes Apprise create a private room on the bot's own homeserver (cannot be disabled).

## ntfy custom server: same guard, vetted at send
ntfy's optional Server field uses `SafeChatServerUrl` (so https only, public hosts only; http and private targets only on self-host with `allow_private_networks`). It has no Messaging transport: `AppriseTransport` re-checks `NtfyChannelType::serverUrlFrom($channel->apprise_url)` with `ChatServerGuard` on every send and fails with the fixed `NtfyChannelType::SERVER_REFUSED`. A saved channel with a now-refused host is kept, never deleted. The default server (`ntfy://topic`) is never checked.
