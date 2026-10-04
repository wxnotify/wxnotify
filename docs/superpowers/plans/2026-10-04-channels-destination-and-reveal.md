# Channels Page: Clear Destination Values and a Reveal-URL Field Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Channels list "Destination" column say what each value is (a gray caption under the value, for all nine channel types), and add an on-demand "Reveal" field for a channel's full Apprise URL on the Edit channel form.

**Architecture:** A new `ChannelType::destinationLabel()` caption method (one fixed string per type) feeds a `description()` on the table's existing Destination column. A new `RevealChannelUrl` action holds the guarded, audited reveal logic (owner only, blocked while impersonating, writes an activity-log row, returns the decrypted URL); a read-only, never-dehydrated `apprise_url_preview` field on the Edit form calls it through Reveal/Hide suffix actions so the URL is never in the page until requested.

**Tech Stack:** Laravel 13 / PHP 8.4, Filament v5, Spatie activitylog, Pest.

**Spec:** `docs/superpowers/specs/2026-10-04-channels-destination-and-reveal-design.md` (binding; read it first).

## Global Constraints

- Captions (fixed strings, never derived from the record), exactly: Email `Email address`; Pushover `Pushover user key`; Discord `Discord webhook`; Slack `Slack webhook`; Microsoft Teams (slug `msteams`) `Teams workflow`; ntfy `ntfy server and topic`; Webhook `Webhook host`; Web Push (slug `webpush`) `Push service`; Telegram `Telegram chat ID`.
- Telegram's `displaySummary()` value loses its `Chat ` prefix (`-100123456`, not `Chat -100123456`). No other `displaySummary()` value changes.
- When a value cannot be read (`—`) or the type is unknown, no caption is shown. The Destination column header stays "Destination"; the Name column and other columns are unchanged.
- `displaySummary()` must still never return a value that alone grants delivery access.
- The reveal field exists only for a record whose `apprise_url` is filled (Pushover, Discord, Slack, Teams, Telegram, ntfy); never for Email, Web Push or Webhook.
- Reveal: on-demand fetch only (the URL is NOT in the page, the Livewire snapshot or form state until Reveal; Hide clears it; reopening the modal starts hidden); owner only (the server re-checks `user_id === auth()->id()`); blocked while impersonating (Reveal disabled with a tooltip, and a forced call is refused with a notification and no value); each successful reveal writes one activity-log entry on the channel (event `revealed`, no properties, never the URL).
- The field is named `apprise_url_preview` (never `apprise_url`), is `->dehydrated(false)` and read-only, so saving the form can never overwrite the real URL or store a revealed value. `saveChannel()` is unchanged.
- Not included: editing the Apprise URL, a copy button, the Webhook channel's URL or secret, re-authentication before reveal, searching by destination.
- Conventions: Pest feature tests; Pint (`vendor/bin/pint --dirty --format agent`) before each commit; commit directly to `main`, never push; every commit message ends with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Before editing, read `.ai/rules/index.md` and the rule files covering touched paths: `filament.md` (`app/Filament/**`), `delivery.md` and `support.md` (`app/Support/**`), `feature.md` (`tests/Feature/**`), `pages.md` (the never-prefill-secrets rule).

## How to run tests (read this once)

Run Pest inside the app container (the host cannot reach the DB):

```bash
docker exec wxnotify-nextgen-front-end-1 sh -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --compact tests/Feature/SomeTest.php'
```

Feature tests fail fast on any unfaked HTTP request. The controller runs the full suite once at the end, in parallel (`vendor/bin/pest --parallel --processes=6` in the container). Pint runs on the host.

## File Structure

| Path | Responsibility |
| --- | --- |
| `app/Support/ChannelTypes/Contracts/ChannelType.php` | new `destinationLabel(): string` |
| `app/Support/ChannelTypes/*ChannelType.php` (nine) | implement `destinationLabel()`; Telegram value loses its prefix |
| `app/Filament/Dashboard/Resources/NotificationChannels/Tables/NotificationChannelsTable.php` | Destination column `description()` |
| `app/Actions/Channels/RevealChannelUrl.php` (new) | the guarded, audited reveal |
| `app/Filament/Dashboard/Resources/NotificationChannels/Schemas/NotificationChannelForm.php` | the `apprise_url_preview` field with Reveal/Hide |
| `tests/Feature/ChannelTypesTest.php`, `tests/Feature/NotificationChannelResourceTest.php` | caption and column tests; Telegram expectation moves |
| `tests/Feature/RevealChannelUrlTest.php` (new) | action tests |
| `.ai/rules/delivery.md`, `.ai/rules/filament.md` | rule notes |

---

### Task 1: Destination captions

**Files:**
- Modify: `app/Support/ChannelTypes/Contracts/ChannelType.php`, the nine `app/Support/ChannelTypes/*ChannelType.php` files (Discord, Email, Ntfy, Pushover, Slack, Teams, Telegram, WebPush, Webhook), `app/Filament/Dashboard/Resources/NotificationChannels/Tables/NotificationChannelsTable.php`
- Test: `tests/Feature/ChannelTypesTest.php`, `tests/Feature/NotificationChannelResourceTest.php`

**Interfaces:**
- Produces: `ChannelType::destinationLabel(): string` on every registered type. Task 2 does not depend on it.

Read `.ai/rules/filament.md` and `.ai/rules/delivery.md` first.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/ChannelTypesTest.php`:

1. Change the Telegram expectation in `telegram's destination summary shows the chat id in full but never the bot token` from `->toBe('Chat -100123456')` to `->toBe('-100123456')`.
2. Add (the file already imports the type classes and `ChannelTypeRegistry`):

```php
test('every channel type captions its destination value with a fixed, secret-free caption', function () {
    $captions = app(ChannelTypeRegistry::class)->all()
        ->mapWithKeys(fn ($type): array => [$type->slug() => $type->destinationLabel()])
        ->all();

    expect($captions)->toEqual([
        'discord' => 'Discord webhook',
        'email' => 'Email address',
        'msteams' => 'Teams workflow',
        'ntfy' => 'ntfy server and topic',
        'pushover' => 'Pushover user key',
        'slack' => 'Slack webhook',
        'telegram' => 'Telegram chat ID',
        'webhook' => 'Webhook host',
        'webpush' => 'Push service',
    ]);

    foreach ($captions as $caption) {
        expect($caption)->not->toContain('://')->not->toContain('•');
    }
});
```

If the registry holds more or fewer types than these nine, report it instead of changing the expectation silently.

In `tests/Feature/NotificationChannelResourceTest.php` (its `beforeEach` signs in `$this->user`), add:

```php
test('the Destination column captions each value with what it is', function () {
    NotificationChannel::factory()->for($this->user)->create([
        'channel_type' => 'pushover', 'label' => 'Work Pushover',
        'apprise_url' => 'pover://abcdefghijklmnopqrstuvwxyz1234@zyxwvutsrqponmlkjihgfedcba4321',
    ]);
    NotificationChannel::factory()->for($this->user)->email('first@example.com')->create(['label' => 'My Email']);
    NotificationChannel::factory()->for($this->user)->create([
        'channel_type' => 'telegram', 'label' => 'Family Chat', 'apprise_url' => 'tgram://123%3AABC-token/-100123456',
    ]);

    livewire(ListNotificationChannels::class)
        ->assertSee('••••1234')
        ->assertSee('Pushover user key')
        ->assertSee('first@example.com')
        ->assertSee('Email address')
        ->assertSee('-100123456')
        ->assertSee('Telegram chat ID')
        ->assertDontSee('ABC-token');
});

test('a destination value that cannot be read shows no caption', function () {
    NotificationChannel::factory()->for($this->user)->create([
        'channel_type' => 'pushover', 'label' => 'Broken Pushover', 'apprise_url' => 'not-a-pushover-url',
    ]);

    livewire(ListNotificationChannels::class)
        ->assertSee('—')
        ->assertDontSee('Pushover user key');
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `... vendor/bin/pest --compact tests/Feature/ChannelTypesTest.php tests/Feature/NotificationChannelResourceTest.php`
Expected: the Telegram test, the captions test (undefined method) and the two column tests FAIL.

- [ ] **Step 3: Add the contract method**

In `app/Support/ChannelTypes/Contracts/ChannelType.php`, after `displaySummary()`:

```php
    /**
     * A short fixed caption saying what `displaySummary()` shows ("Pushover user
     * key", "Email address"), displayed in small gray text under the value in the
     * subscriber's Channels table. Never derived from the record, so it can never
     * carry a secret.
     */
    public function destinationLabel(): string;
```

- [ ] **Step 4: Implement it in the nine types**

Add to each class (next to its `displaySummary()`):

```php
    public function destinationLabel(): string
    {
        return '<caption>';
    }
```

with `<caption>`: Discord `Discord webhook`; Email `Email address`; Ntfy `ntfy server and topic`; Pushover `Pushover user key`; Slack `Slack webhook`; Teams `Teams workflow`; Telegram `Telegram chat ID`; WebPush `Push service`; Webhook `Webhook host`.

In `TelegramChannelType::displaySummary()` change `return 'Chat '.rawurldecode($matches[1]);` to `return rawurldecode($matches[1]);` and update its docblock sentence to say the caption (not a "Chat" prefix) says what the value is.

- [ ] **Step 5: Add the column description**

In `NotificationChannelsTable::configure()` replace the `destination` column with:

```php
                TextColumn::make('destination')
                    ->label('Destination')
                    ->state(fn (NotificationChannel $record): string => app(ChannelTypeRegistry::class)->find($record->channel_type)?->displaySummary($record) ?? '—')
                    ->description(function (NotificationChannel $record): ?string {
                        $type = app(ChannelTypeRegistry::class)->find($record->channel_type);

                        return $type !== null && $type->displaySummary($record) !== '—' ? $type->destinationLabel() : null;
                    }),
```

- [ ] **Step 6: Run the tests**

Run: `... vendor/bin/pest --compact tests/Feature/ChannelTypesTest.php tests/Feature/NotificationChannelResourceTest.php tests/Feature/ChannelDeletionGuardTest.php`
Expected: PASS. Also run `grep -rln "displaySummary\|ChannelType" tests` and confirm every file touching channel types passes.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/ChannelTypes app/Filament/Dashboard/Resources/NotificationChannels/Tables/NotificationChannelsTable.php tests/Feature/ChannelTypesTest.php tests/Feature/NotificationChannelResourceTest.php
git commit -m "Caption each channel's Destination value with what it is

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: The reveal-URL field

**Files:**
- Create: `app/Actions/Channels/RevealChannelUrl.php`
- Modify: `app/Filament/Dashboard/Resources/NotificationChannels/Schemas/NotificationChannelForm.php`
- Test: `tests/Feature/RevealChannelUrlTest.php` (new), `tests/Feature/NotificationChannelResourceTest.php` (append)

**Interfaces:**
- Produces: `RevealChannelUrl::__invoke(NotificationChannel $channel): array{url: ?string, error: ?string}`; form field `apprise_url_preview`; suffix actions named `revealAppriseUrl` and `hideAppriseUrl`.

Read `.ai/rules/filament.md` and `.ai/rules/pages.md` (never prefill a secret into form state; a dotted or wrongly named field can wipe a real column through mass assignment) first. Use the `search-docs` tool or the Filament v5 vendor sources for the exact way a form component's suffix action reads the record and sets sibling state inside a table `EditAction` modal.

- [ ] **Step 1: Write the failing action tests**

`tests/Feature/RevealChannelUrlTest.php`:

```php
<?php

use App\Actions\Channels\RevealChannelUrl;
use App\Models\NotificationChannel;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use STS\FilamentImpersonate\ImpersonateManager;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->channel = NotificationChannel::factory()->for($this->user)->create([
        'channel_type' => 'pushover',
        'label' => 'Work Pushover',
        'apprise_url' => 'pover://uKey1234567890@aTok0987654321',
    ]);
});

test('the owner gets the exact decrypted URL back', function () {
    expect((new RevealChannelUrl)($this->channel))->toBe(['url' => 'pover://uKey1234567890@aTok0987654321', 'error' => null]);
});

test('a reveal is recorded on the channel, by whom and when, never with the URL', function () {
    (new RevealChannelUrl)($this->channel);

    $entry = Activity::where('subject_type', NotificationChannel::class)->where('subject_id', $this->channel->id)->where('event', 'revealed')->sole();

    expect($entry->causer_id)->toBe($this->user->id)
        ->and($entry->description)->toBe('Revealed the channel destination URL')
        ->and(json_encode($entry->toArray()))->not->toContain('uKey1234567890')->not->toContain('aTok0987654321');
});

test('staff impersonating the subscriber are refused, get no URL, and leave no log entry', function () {
    $this->mock(ImpersonateManager::class, fn ($mock) => $mock->shouldReceive('isImpersonating')->andReturn(true));

    expect((new RevealChannelUrl)($this->channel))->toBe(['url' => null, 'error' => 'Not available while impersonating a subscriber.'])
        ->and(Activity::where('event', 'revealed')->exists())->toBeFalse();
});

test('another subscriber\'s channel is refused', function () {
    $stranger = NotificationChannel::factory()->for(User::factory())->create(['apprise_url' => 'discord://123456789/theirtoken/']);

    expect((new RevealChannelUrl)($stranger))->toBe(['url' => null, 'error' => 'You can only reveal your own channels.'])
        ->and(Activity::where('event', 'revealed')->exists())->toBeFalse();
});

test('a channel with no Apprise URL has nothing to reveal', function () {
    $email = NotificationChannel::factory()->for($this->user)->email()->create();

    expect((new RevealChannelUrl)($email))->toBe(['url' => null, 'error' => 'This channel has no URL to reveal.'])
        ->and(Activity::where('event', 'revealed')->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `... vendor/bin/pest --compact tests/Feature/RevealChannelUrlTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Create the action**

`app/Actions/Channels/RevealChannelUrl.php`:

```php
<?php

namespace App\Actions\Channels;

use App\Models\NotificationChannel;
use STS\FilamentImpersonate\ImpersonateManager;

/**
 * Hands a channel's full Apprise URL back to its owner, on request.
 *
 * The URL holds the subscriber's own tokens (their Pushover user key and application
 * token, their bot token or webhook), so it is shown only on demand, only to the owner,
 * never while staff impersonate the subscriber, and each reveal is written to the
 * activity log without the URL. `NotificationChannel`'s own log options exclude
 * `apprise_url` and `config`, and this entry carries no properties at all.
 */
class RevealChannelUrl
{
    /**
     * @return array{url: ?string, error: ?string}
     */
    public function __invoke(NotificationChannel $channel): array
    {
        if (app(ImpersonateManager::class)->isImpersonating()) {
            return $this->refused('Not available while impersonating a subscriber.');
        }

        if ($channel->user_id !== auth()->id()) {
            return $this->refused('You can only reveal your own channels.');
        }

        if (blank($channel->apprise_url)) {
            return $this->refused('This channel has no URL to reveal.');
        }

        activity()
            ->performedOn($channel)
            ->causedBy(auth()->user())
            ->event('revealed')
            ->log('Revealed the channel destination URL');

        return ['url' => $channel->apprise_url, 'error' => null];
    }

    /**
     * @return array{url: null, error: string}
     */
    private function refused(string $message): array
    {
        return ['url' => null, 'error' => $message];
    }
}
```

Run the action tests: expected PASS.

- [ ] **Step 4: Write the failing form tests**

Append to `tests/Feature/NotificationChannelResourceTest.php` (it imports `ListNotificationChannels`, `NotificationChannel`, `TestAction`, `livewire`):

```php
test('the edit form offers a hidden URL field for channels that have an Apprise URL', function (string $channelType, string $url) {
    $channel = NotificationChannel::factory()->for($this->user)->create(['channel_type' => $channelType, 'apprise_url' => $url]);

    livewire(ListNotificationChannels::class)
        ->mountAction(TestAction::make('edit')->table($channel))
        ->assertSchemaComponentExists('apprise_url_preview')
        ->assertSchemaStateSet(['apprise_url_preview' => null]);
})->with([
    'pushover' => ['pushover', 'pover://uKey1234567890@aTok0987654321'],
    'discord' => ['discord', 'discord://123456789/abcDEF-token_123/'],
    'slack' => ['slack', 'slack://T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXWXYZ/'],
    'telegram' => ['telegram', 'tgram://123%3AABC-token/-100123456'],
    'ntfy' => ['ntfy', 'ntfy://wxnotify-a1b2c3'],
]);

test('the edit form has no URL field for email, web push or webhook channels', function () {
    $channels = [
        NotificationChannel::factory()->for($this->user)->email()->create(),
        NotificationChannel::factory()->for($this->user)->webPush()->create(),
        NotificationChannel::factory()->for($this->user)->webhook()->create(),
    ];

    foreach ($channels as $channel) {
        livewire(ListNotificationChannels::class)
            ->mountAction(TestAction::make('edit')->table($channel))
            ->assertSchemaComponentDoesNotExist('apprise_url_preview');
    }
});

test('before Reveal the URL is nowhere in the rendered edit form or its snapshot', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create([
        'channel_type' => 'pushover', 'apprise_url' => 'pover://uKey1234567890@aTok0987654321',
    ]);

    livewire(ListNotificationChannels::class)
        ->mountAction(TestAction::make('edit')->table($channel))
        ->assertDontSee('uKey1234567890')
        ->assertDontSee('aTok0987654321');
});

test('saving the edit form never changes the stored URL, even if the preview field is submitted', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create([
        'channel_type' => 'pushover', 'label' => 'Old', 'apprise_url' => 'pover://uKey1234567890@aTok0987654321',
    ]);

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('edit')->table($channel), data: [
            'label' => 'New',
            'apprise_url_preview' => 'pover://attacker@attacker',
        ])
        ->assertHasNoActionErrors();

    $fresh = $channel->fresh();

    expect($fresh->label)->toBe('New')->and($fresh->apprise_url)->toBe('pover://uKey1234567890@aTok0987654321');
});
```

If an assertion name used above does not exist in the installed Filament v5 (`assertSchemaComponentExists`, `assertSchemaComponentDoesNotExist`, `assertSchemaStateSet`), use the nearest equivalent the suite already uses (grep `tests/` for `assertSchema`/`assertFormField`) with the same meaning, and say so in your report.

- [ ] **Step 5: Run them to verify they fail**

Run: `... vendor/bin/pest --compact tests/Feature/NotificationChannelResourceTest.php --filter="hidden URL field|no URL field|nowhere in the rendered|never changes the stored URL"`
Expected: the field-presence tests FAIL (no such field); the not-in-page test and the save test pass or fail depending on current behavior; record which in your report.

- [ ] **Step 6: Add the field**

In `NotificationChannelForm::configure()`, add after the label input (and before the webhook `Group`):

```php
                TextInput::make('apprise_url_preview')
                    ->label('Destination URL')
                    ->readOnly()
                    // Not a column and never saved: the real URL is `apprise_url`, which this form must
                    // not be able to touch, and a revealed value must never be stored.
                    ->dehydrated(false)
                    ->placeholder('••••••••••••••••')
                    ->helperText('Hidden until you reveal it. Treat it like a password: anyone who has it can send messages to this destination.')
                    ->visible(fn (?NotificationChannel $record): bool => filled($record?->apprise_url))
                    ->suffixActions([
                        Action::make('revealAppriseUrl')
                            ->label('Reveal')
                            ->icon(Heroicon::Eye)
                            ->visible(fn (Get $get): bool => blank($get('apprise_url_preview')))
                            ->disabled(fn (): bool => app(ImpersonateManager::class)->isImpersonating())
                            ->tooltip(fn (): string => app(ImpersonateManager::class)->isImpersonating()
                                ? 'Not available while impersonating a subscriber'
                                : 'Show the full URL')
                            ->action(function (?NotificationChannel $record, Set $set): void {
                                $result = $record === null
                                    ? ['url' => null, 'error' => 'This channel has no URL to reveal.']
                                    : app(RevealChannelUrl::class)($record);

                                if ($result['url'] === null) {
                                    Notification::make()->danger()->title('Couldn\'t reveal the URL')->body($result['error'])->send();

                                    return;
                                }

                                $set('apprise_url_preview', $result['url']);
                            }),
                        Action::make('hideAppriseUrl')
                            ->label('Hide')
                            ->icon(Heroicon::EyeSlash)
                            ->visible(fn (Get $get): bool => filled($get('apprise_url_preview')))
                            ->action(fn (Set $set) => $set('apprise_url_preview', null)),
                    ]),
```

Add the imports the code needs (`App\Actions\Channels\RevealChannelUrl`, `Filament\Actions\Action`, `Filament\Notifications\Notification`, `Filament\Schemas\Components\Utilities\Get`, `Filament\Schemas\Components\Utilities\Set`, `Filament\Support\Icons\Heroicon`, `STS\FilamentImpersonate\ImpersonateManager`). Verify the `Heroicon` case names (`Eye`, `EyeSlash`) exist; use the real ones.

If a suffix action's closure does not receive the record this way inside a table `EditAction` modal, resolve it through the component (for example `$component->getRecord()`) as Filament v5 provides; the behavior must be exactly: refuse with a notification when `RevealChannelUrl` returns no URL, otherwise set the field's state to the URL.

- [ ] **Step 7: Prove Reveal and Hide through the form**

Add one test that drives the real suffix actions in the mounted Edit modal: Reveal sets `apprise_url_preview` to the exact URL (and the Activity row exists); Hide returns it to null; and while `ImpersonateManager::isImpersonating()` is mocked true Reveal leaves it null and the mocked refusal notification is sent. Use Filament v5's way of calling a form component's nested action from a mounted action (check the Filament testing docs for "actions in schema components"; the existing webhook edit tests in this file show the mounting style). If driving the nested action from Pest is genuinely not possible in the installed Filament, say so in your report and keep the RevealChannelUrl action tests as the proof of the logic; the controller will verify Reveal and Hide in the real browser.

- [ ] **Step 8: Run the tests**

Run: `... vendor/bin/pest --compact tests/Feature/RevealChannelUrlTest.php tests/Feature/NotificationChannelResourceTest.php tests/Feature/ChannelTypesTest.php tests/Feature/WebhookChannelTest.php tests/Feature/WebhookChannelOptionsTest.php`
Expected: PASS (the webhook edit tests prove the new field does not disturb that form).

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Channels/RevealChannelUrl.php app/Filament/Dashboard/Resources/NotificationChannels/Schemas/NotificationChannelForm.php tests/Feature/RevealChannelUrlTest.php tests/Feature/NotificationChannelResourceTest.php
git commit -m "Let a channel's owner reveal its full Apprise URL on demand

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Rule notes

**Files:**
- Modify: `.ai/rules/delivery.md`, `.ai/rules/filament.md`

- [ ] **Step 1: Append the notes**

Append to `.ai/rules/delivery.md`:

```markdown

## Channel types caption their destination value
Every channel type implements `destinationLabel()`, a short FIXED caption ("Pushover user key", "Email address") shown in gray under `displaySummary()`'s value in the subscriber's Channels table, so a masked fragment is never an unexplained string. The caption is never derived from the record, so it cannot carry a secret, and `displaySummary()` must still never return a value that alone grants delivery access. A new channel type must supply both; the table shows no caption when the value is `—`. Telegram's value is the bare chat ID (the caption says what it is).
```

Append to `.ai/rules/filament.md`:

```markdown

## The Edit channel form reveals the Apprise URL on demand, never prefilled
`NotificationChannelForm`'s `apprise_url_preview` field (only for a record with an `apprise_url`: Pushover, Discord, Slack, Teams, Telegram, ntfy) starts empty and is filled by its Reveal suffix action through `App\Actions\Channels\RevealChannelUrl`, so the URL is never in the page, the Livewire snapshot or form state until requested; Hide clears it and reopening the modal starts hidden. The field is read-only and `->dehydrated(false)`, deliberately NOT named `apprise_url`, so submitting the form can neither overwrite the real URL nor save a revealed value. `RevealChannelUrl` is owner-only (`user_id === auth()->id()`), refuses while staff impersonate (the Reveal button is disabled too), and writes an activity-log entry (event `revealed`, no properties) on every successful reveal, never the URL. Every Apprise URL is built from the subscriber's own input, so revealing it exposes no platform secret; keep it that way (a channel type that embeds a platform credential in its Apprise URL must not use this field).
```

- [ ] **Step 2: Commit**

```bash
git add .ai/rules/delivery.md .ai/rules/filament.md
git commit -m "Record the channel destination caption and URL reveal rules

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Controller verification (not a task for an implementer)

After Task 3 and the final review: run the full suite in parallel with 6 processes. Then check in the browser as a throwaway subscriber with a Pushover, a Discord and an Email channel (and one Telegram): the two-line Destination cells (value plus gray caption), the Edit modal's URL field absent for Email, present and masked for Pushover, the page source free of the URL before the click, Reveal and Hide in the real modal (with the reveal appearing in the activity log), and a save after a reveal leaving the URL unchanged. Delete the throwaway data. Do not push.
