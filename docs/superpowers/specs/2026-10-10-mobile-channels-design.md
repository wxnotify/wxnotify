# Mobile Channels Management (notification channels in the app) — Design

Source: owner request — slice 4b of the mobile app, the channels management that slice 4 (`2026-10-05-mobile-parity-management-design.md`) left on the web. It lives under the Settings hub as a fourth row. Channel creation, verification and editing for the simple channel types only.

## Goal

A subscriber can see their notification channels, add an Email, ntfy, Discord, Slack, Telegram or Teams channel, confirm it with the code WxNotify sends to it, rename it, send a test through it and delete it, from the app, with the same limits and results as the dashboard.

## Decisions (user-approved, from brainstorming)

- **Types the app can create: Email, ntfy, Discord, Slack, Telegram, Microsoft Teams** (one or two fields each). Pushover and Webhook stay on the dashboard; Web Push needs a browser and Mobile Push is created by the app's own push registration. The app still lists every channel the user has, of any type, and can rename, test and delete the types it did not create. Rejected: adding Pushover; everything creatable (Webhook's headers, signing, proxy and retries are a slice of their own).
- **Edit means rename only,** as on the dashboard for these types. To change where a channel sends, add a new channel and delete the old one. No destination is ever shown in the app, so there is no Reveal. Rejected: replacing the destination in the editor (new behaviour on both front ends, with a re-verification flow to design).
- **Neutral field descriptions beside the Filament forms.** Each creatable type declares its setup fields as plain descriptors (key, label, input kind, required, help, placeholder, default, validation rules). The API publishes them and validates with them; the Filament forms are untouched. A parity test keeps the two in step. Rejected: building the descriptors from Filament components (closure rules, tied to Filament internals); rebuilding the dashboard forms from the descriptors (a regression risk on six working forms for a mobile slice).
- **The phone's own push channel (Mobile Push) is listed and read-only.** It is switched on and off in Account.
- **The Settings hub gains a "Channels" row** ("Where your alerts are sent"), after Alert rules and Locations.

## Backend

Routes join the existing `/api/v1` group (`auth:sanctum`, `ability:app`, `ExtendAppTokenExpiry`, `throttle:app-api`), return Eloquent API Resources (the default `data` wrapper) and are scoped to the token's user (another user's id is a 404). Each operation is an Action, validation is a Form Request, and activity writes pin the causer with `CauserResolver::withCauser` (`.ai/rules/actions.md`, `.ai/rules/api.md`). Errors use the existing shape, `{error: '<code>', message: '<text>'}`. A write during maintenance mode answers 503 with a plain-text body (`NotificationChannel` uses `BlockedDuringMaintenance`), which the app already handles. No response carries `apprise_url`, `config`, a URL, a token or a confirmation code.

### Setup field descriptors

`ChannelType` gains `appSetupFields(): ?array`. `null` means the app cannot create this type (Pushover, Webhook, Web Push, Mobile Push). Otherwise it returns `ChannelSetupField` value objects (`App\Support\ChannelTypes\ChannelSetupField`):

| Property | Meaning |
| --- | --- |
| `key` | The request field, flat at the top level of the create body (`webhook_url`, `to`, `topic`, `server`, `bot_token`, `chat_id`). |
| `label`, `help`, `placeholder` | Display text; `help` and `placeholder` are nullable. |
| `input` | `text`, `email`, `url` or `secret`. `secret` is for values that act as a password: bot tokens and webhook URLs. |
| `required` | Bool. |
| `default` | Nullable string. Email defaults to the user's address. |
| `rules` | Laravel validation rules, used by the API only and never published. |

The six types and their fields are exactly the ones their `formSchema()` shows today, with the same rules: Email `to` (email); ntfy `topic` (alpha-dash) and optional `server` (url); Discord, Slack and Teams `webhook_url` with their URL patterns (Teams reuses `parseWorkflowUrl`); Telegram `bot_token` and `chat_id`. Rule definitions that are plain strings or small closures are shared by reference between the Filament field and the descriptor (a constant or a static method on the type), not copied, wherever that is straightforward; the parity test covers the rest. The create request builds its rules from the chosen type's descriptors and then calls `buildDestination()`; an `InvalidArgumentException` from it becomes a 422 on the offending field.

### Endpoints

- `GET /channel-types`: the six creatable types in the catalog's sort order (`ChannelTypeRegistry::forPicker()` filtered to those with descriptors): `slug`, `label`, `can_add` (`User::canAddChannel()`), `unavailable_reason` (`channel_limit` when a Free account has used its no-cost slot, else null) and `fields` (the descriptors without `rules`).
- `GET /channels`: every channel of the user, any type, oldest first: `id`, `label`, `channel_type`, `type_label`, `destination` (the type's `displaySummary()`, or null when it is `—`), `destination_label` (the type's `destinationLabel()`, null with the destination), `verified` (bool), `managed_by_app` (true for Mobile Push), `created_at`.
- `POST /channels` `{channel_type, label?, <fields>}`: 201 with the channel, created unverified, code sent. Errors: 422 per field; 422 `channel_type` for a type that is unknown or not creatable; 422 `{error: 'channel_limit'}` when `canAddChannel()` is false; 502 `{error: 'code_not_sent'}` when the code could not be delivered, in which case no row remains (see `BeginChannelConnection`). `label` is optional, at most 255 characters, and defaults to the type's label (or the destination's own, for types that build one).
- `POST /channels/{id}/verify` `{code}`: 200 with the channel (idempotent for an already verified one); 422 `{error: 'invalid_code'}` for a wrong or expired code, with the message "That code didn't match, or it expired. Request a new code if you need one."
- `POST /channels/{id}/resend`: 204; 409 `already_verified`; 502 `code_not_sent`. A resend resets the attempt count.
- `POST /channels/{id}/test`: 204; 409 `not_verified`; 502 `{error: 'test_failed'}`.
- `PUT /channels/{id}` `{label}`: 200 with the channel. `label` is required, at most 255 characters.
- `DELETE /channels/{id}`: 204. 409 `{error: 'channel_in_use', alerts: [<names as plain strings>]}` when `ChannelDeletionGuard::alertsLeftWithoutChannel()` is not empty (the names are never the guard's HTML-escaped summary), as `DELETE /locations/{id}` does.
- Every endpoint except `GET /channel-types` and `GET /channels` answers 409 `channel_managed_by_app` for a Mobile Push channel.

### Actions

- **`BeginChannelConnection`** takes the user, the channel type, the validated data and an optional label and returns the pending channel, or null when the code could not be sent. It is the body that today sits in the `ConnectChannel` page's second step: build the destination, find an existing Web Push channel for the same browser endpoint and refresh it instead of duplicating it, store the channel as unverified (a refreshed one goes back to unverified), send the code through `DispatchChannelConfirmationCode`, and delete a channel that did not already exist if the send fails. The Filament page calls it, so the dashboard behaviour does not change and its existing tests are the safety net. The Web Push branch exists only for that page; the API never reaches it.
- **`VerifyChannelConfirmationCode`** counts wrong codes per channel in the cache and, after 5, clears the stored code so only a resend produces a new one. A six-digit code with no attempt limit can be brute-forced by anyone holding an app token. The count resets on a resend and on success. This also protects the dashboard's two verify entry points, which call the same action.
- **`RenameChannel`**, **`DeleteChannel`** wrap the model writes with the causer pinned and the guard applied; **`SendTestNotification`**, **`DispatchChannelConfirmationCode`** are used as they are.

### Limits

`RateLimiter::for('app-channel-send')`: 10 per hour per user, shared by create, resend and test, because each sends a real message to a destination the subscriber typed. The requests that carry secrets (create) are never logged; the existing request logging rule applies, and the limiter key is the user id, not any part of the body.

## App (`mobile/`)

Every screen is a native component from EDGE elements registered with `Route::native()`, with the existing palette and icons, written to pass `native:validate` (`.ai/rules` and the slice 4 notes: bare tags for the elements its list does not know, no literal handler calls).

### Screens

- **Settings hub (`/settings`):** a fourth row, "Channels — Where your alerts are sent", pushing `/channels`.
- **Channels (`/channels`):** an "Add channel" button on top, then a list. A row shows the label, the type with its destination summary ("Discord webhook ••••ab12") and "Not verified" when it applies. The Mobile Push row is not tappable and reads "Managed in Account". It paints from the cache, refreshes, and offline shows the cached list with the usual note. Back returns to Settings.
- **New channel (`/channels/new`):** a list of the types from `GET /channel-types`; a type with `can_add` false is disabled and shows its reason ("Your plan's free channel is in use. Remove a channel or upgrade."). Picking a type shows its fields from the descriptors plus an optional label. A `secret` field is masked with a reveal toggle. "Send code" posts; on success the screen is replaced by `/channels/{id}`, so a half-filled form is never returned to. Per-field validation messages sit under the fields, `channel_limit` and `code_not_sent` show beside the button, and offline says to reconnect. The field values live only in the component state, are cleared after a successful send and are never cached.
- **Channel (`/channels/{id}`):** the label field and Save (the draft and discard prompt of `EditsADraft`), the type and masked destination read-only. When unverified it opens with "We sent a 6-digit code to this channel", a code field, Verify and Resend code; a wrong code shows `invalid_code`'s message under the field. When verified it shows Send test, with the result beside the button, and Delete. Delete asks first; `channel_in_use` shows "Can't delete X. It's the only channel for: …"; a channel already deleted elsewhere returns to the list.
- **Rule editor:** the "no channels yet" line now reads "You have no channels yet. Turn on push notifications in Account, or add one under Settings → Channels."

### Offline and cache

`LocalCache` gains the channel list (rewritten on each refresh, wiped on sign-out with everything else). Descriptors are fetched fresh whenever New channel opens and never cached. Every write needs the network and says "You're offline. Reconnect to save." beside the buttons, keeping the draft.

## Testing

- **Backend:** a feature test file per endpoint group covering success, 422 per field for each of the six types, the Free-slot `channel_limit` (and Email never counting), `code_not_sent` leaving no row, `invalid_code`, the attempt limit (the sixth try fails even with the right code until a resend), idempotent verify, `already_verified`, `not_verified`, `test_failed`, the deletion guard's names, Mobile Push read-only on every write, another user's id being a 404, the rate limit, no response ever containing `apprise_url`, `config` or the code, the activity rows carrying the user as causer, and the maintenance-mode 503. A parity test per creatable type feeds the same accepted and rejected samples to the Filament form and to the API and asserts the same outcome and the same stored destination. The existing `ConnectChannel` page tests must pass unchanged.
- **App:** a fake network for each state of each screen: list (rows, empty, offline, error, Mobile Push row), New channel (types, disabled type, fields from descriptors, masked secret, 422 per field, limit, code not sent, success replaces with the channel screen), Channel (unverified and verified states, wrong code, resend, rename and discard, send test result, delete, delete guard, already gone), the sign-out wipe of the new cache, the Settings row, and `native:validate` clean.
- **Device:** Android emulator against the dev server once the backend is deployed (list, add an Email channel and confirm it, rename, test, delete, the guard, offline). iOS is not verified.

## Rules to record

In `.ai/rules/api.md`: the channel endpoints send real messages and are throttled per user; a verification code is attempt-limited; responses never carry the destination, config or code; Mobile Push is read-only through the API. In `.ai/rules/actions.md`: `BeginChannelConnection` is the one way a channel is created and sent its first code, for the dashboard and the API. In `.ai/rules/support.md`: a creatable channel type declares `appSetupFields()` and the parity test must cover it.

## Out of scope

Creating Pushover, Webhook or Web Push channels; changing a channel's destination; revealing a destination; bulk delete; an offline write queue; message templates; Traccar linking; iOS device verification.

## Follow-ups noticed, not changed here

- ntfy's optional "server" is a subscriber-supplied URL the server sends to; the dashboard checks only that it is a URL, and the app will do the same. Whether it needs the webhook channel's `SafeWebhookUrl` guard is a separate decision.
- The Email channel sends a confirmation message to any address the subscriber types. The confirmation code and the new send limit bound abuse; a per-destination limit is not added here.
