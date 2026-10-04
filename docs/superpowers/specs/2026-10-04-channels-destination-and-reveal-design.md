# Channels Page: Clear Destination Values and a Reveal-URL Field — Design

Source: owner request — the Channels list "Destination" column is nebulous for some channel types (for Pushover it shows a masked user key with nothing saying so), and the Edit channel form should be able to show the full Apprise URL behind a hide/reveal control that starts hidden.

## Goal

1. **Destination column**: say what each value is, in a consistent way for all nine channel types, so a subscriber can read the column and tell channels of the same type apart.
2. **Reveal field**: on the Edit channel form, let the owner reveal the channel's full Apprise URL on demand, hidden by default, without the URL ever sitting in the page while hidden.

## Decisions (user-approved, from brainstorming)

### Destination column

- **Two-line cell**: the value on top (as today) and a small gray caption underneath saying what the value is. Rejected: a caption inside the value (wide cells), folding the value into the Name column.
- **Identity only**: the caption names the kind of value; no settings summary (priority, devices, method). Two channels on one account (same Pushover user key) look identical except for their Name, which is the user's own label. Rejected: a settings summary per type, and showing it only when two would look alike.
- **A new method on the channel-type contract**: `ChannelType::destinationLabel(): string`, a short fixed caption per type. `displaySummary()` keeps its job and its rule that it never returns a value that alone grants delivery access.

| Type | Value (`displaySummary()`) | Caption (`destinationLabel()`) |
| --- | --- | --- |
| Email | the address | Email address |
| Pushover | `••••` + last 4 of the user key | Pushover user key |
| Discord | `••••` + last 4 of the webhook token | Discord webhook |
| Slack | `••••` + last 4 of the webhook token | Slack webhook |
| Microsoft Teams | `••••` + last 4 of the workflow secret | Teams workflow |
| ntfy | `host ••••xyz` (host omitted for public ntfy.sh) | ntfy server and topic |
| Webhook | the host | Webhook host |
| Web Push | the push service host | Push service |
| Telegram | the chat ID (**without** the old "Chat " prefix) | Telegram chat ID |

- **Telegram's value changes** from `Chat 123456` to `123456` because its caption now says what it is. No other value changes.
- **Web Push** keeps its push-service host: it cannot tell two browsers of one kind apart, but the browser (for example "Chrome on macOS") is already in the auto-generated Name. Unchanged.
- When a value cannot be read (`—`), no caption is shown.
- Unchanged: the Name column, the other columns, and what is searchable.

### Reveal field

- **On-demand fetch**: the URL is not in the page, the Livewire snapshot or the form state until the user clicks Reveal. Reveal asks the server for it and fills a read-only field; Hide clears it. Closing and reopening the Edit modal always starts hidden. Rejected: Filament's built-in password-with-reveal field, which puts the URL in the page from the first render and only masks it visually (the project's own rule: never pre-fill a secret into form state, see `.ai/rules/pages.md`).
- **Only for channels that have an Apprise URL**: Pushover, Discord, Slack, Teams, Telegram, ntfy. Email, Web Push and Webhook channels (no `apprise_url`) do not get the field.
- **No platform secret is exposed**: every Apprise URL is built from values the subscriber entered themselves (their own Pushover user key and application token, their Telegram bot token, their own webhook URLs and ntfy topic), so revealing it to the owner shows only what they supplied.
- **Owner only**: the server re-checks that the channel belongs to the signed-in user before returning anything.
- **Impersonation is blocked**: while staff impersonate a subscriber, Reveal is disabled with an explanatory note, and Filament refuses a disabled action on the server before its closure runs, so a forced call while impersonating is silently dropped; `RevealChannelUrl` independently refuses (with its fixed messages, returning no value) if it is ever reached. Rejected: allowing it (staff could read a subscriber's webhook and bot tokens).
- **Audited**: each successful reveal writes an activity-log entry on the channel (who, when, which channel), never the URL. Rejected: no logging.
- **Never saved**: the field has its own name (not `apprise_url`) and is not dehydrated, so submitting the form can neither overwrite the real URL nor store a revealed value.
- Not included: editing the Apprise URL, a copy button, showing the Webhook channel's URL or secret (its edit form already has its own fields), re-authenticating before reveal.

## Implementation notes

### Destination column

- `App\Support\ChannelTypes\Contracts\ChannelType` gains `destinationLabel(): string`; all nine types implement it with the captions above (fixed strings, never derived from the record).
- `TelegramChannelType::displaySummary()` returns the decoded chat ID without the "Chat " prefix.
- `NotificationChannelsTable`'s `destination` column keeps `->state()` from `displaySummary()` and adds `->description()` returning the type's `destinationLabel()` for that record, or `null` when the value is `—` or the type is unknown.

### Reveal field

- In `NotificationChannelForm`, a read-only `TextInput` named `apprise_url_preview`, `->dehydrated(false)`, `->placeholder('••••••••••••••••')`, visible only for a record whose `apprise_url` is filled (use `fn (?NotificationChannel $record): bool => filled($record?->apprise_url)`). It is never filled by `mutateRecordDataUsing`/`fill`, so its initial state is null.
- Two suffix actions on it: **Reveal** (shown while the field's state is blank) and **Hide** (shown while it is filled). Reveal's closure takes the record and the `Set` utility:
  - shows a danger notification and sets no value when `RevealChannelUrl` refuses, which it does when `app(ImpersonateManager::class)->isImpersonating()` or when `$record->user_id !== auth()->id()` (while impersonating the button is disabled, so Filament never runs the closure and this refusal is defence in depth);
  - otherwise writes one activity-log entry (`activity()->performedOn($record)->causedBy(auth()->user())->event('revealed')->log('Revealed the channel destination URL')`, with no properties) and sets `apprise_url_preview` to `$record->apprise_url`.
  - Hide sets the state to null.
- While impersonating, the Reveal action is disabled with a tooltip ("Not available while impersonating a subscriber").
- The Edit action's save (`saveChannel`) is unchanged: `apprise_url_preview` is not dehydrated, so it never reaches `$record->update()`.
- The activity-log observer ignores events not in its type map, so a `revealed` entry raises no activity alert. `NotificationChannel`'s own log options still exclude `apprise_url` and `config`, so nothing about the URL reaches the log.

## Testing

Pest feature tests (container; full suite in parallel with 6 processes):

- **Destination**: every registered type returns a non-empty caption and none contains a secret; the Telegram value no longer has the prefix (the existing `ChannelTypesTest` Telegram expectation moves from `Chat -100123456` to `-100123456`); the table renders the caption under the value for each type, and omits it for a value of `—`.
- **Reveal field**: absent for Email, Web Push and Webhook channels, present for the six Apprise types; before Reveal the rendered form and snapshot do not contain any part of the URL; Reveal fills exactly the decrypted URL; Hide clears it; reopening starts hidden; Reveal while impersonating is refused and returns nothing; revealing another subscriber's channel is refused; a successful reveal writes one activity row on the channel whose properties never contain the URL; saving the form after a reveal leaves `apprise_url`, `label` and `config` as expected (nothing about the URL changes).
- **Browser check** (controller, after execution): a throwaway subscriber with a Pushover and an Email channel (and a Discord one): the two-line Destination cells, the Edit modal's field absent for Email, present and hidden for Pushover, the page source free of the URL before the click, Reveal and Hide working in the real modal, and the activity log entry. Delete the throwaway data afterwards.

## Rules to record

- `.ai/rules/delivery.md`: a new channel type must implement `destinationLabel()` (a fixed caption, never from the record) alongside `displaySummary()`; `displaySummary()` must still never return a value that grants delivery access.
- `.ai/rules/filament.md`: the Edit channel form's `apprise_url_preview` is revealed on demand, never dehydrated and never prefilled; reveals are owner-only, blocked while impersonating, and logged without the URL.
