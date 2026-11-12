# Mobile Channels Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A subscriber can list, add (Email, ntfy, Discord, Slack, Telegram, Teams), confirm with a code, rename, test and delete notification channels from the app, reached from a "Channels" row on the Settings hub.

**Architecture:** The backend gains a `/api/v1` channels API. Each creatable channel type declares neutral setup-field descriptors (`appSetupFields()`) that the API publishes and validates with. The dashboard wizard's "create pending channel and send the code" body moves into a `BeginChannelConnection` action that both the Filament page and the API call. The app gets three native screens (list, add, channel) on the existing `WxNotifyApi` / `LocalCache` / `EditsADraft` patterns.

**Tech Stack:** Laravel 12 / PHP 8.4 / Pest / Filament (existing); NativePHP Mobile v4 with `nativephp/mobile-ui` in `mobile/`.

**Spec:** `docs/superpowers/specs/2026-10-10-mobile-channels-design.md`

## Global Constraints

- Routes join the existing `/api/v1` group (`auth:sanctum`, `ability:app`, `ExtendAppTokenExpiry`, `throttle:app-api`), return Eloquent API Resources (default `data` wrapper), and are scoped to the token's user (another user's id is a 404).
- Errors use the shape `{error: '<code>', message: '<text>'}`. A write during maintenance answers 503 with a plain-text body (`NotificationChannel` uses `BlockedDuringMaintenance`).
- No response carries `apprise_url`, `config`, a URL, a token or a confirmation code. Requests that carry secrets are never logged.
- The phone's own push channel (`mobile_push`) is listed and read-only: every write endpoint answers 409 `channel_managed_by_app`.
- Activity writes pin the causer with `CauserResolver::withCauser` (the API has no session; `.ai/rules/actions.md`).
- Creatable types: `email`, `ntfy`, `discord`, `slack`, `telegram`, `msteams`. Pushover, Webhook, Web Push and Mobile Push return `null` from `appSetupFields()`.
- Edit means rename only. No destination is ever shown in the app.
- Create and resend and test are throttled by `app-channel-send`: 10 per hour per user, shared. A confirmation code is attempt-limited: after 5 wrong codes the stored code is cleared.
- App: every screen is a native component (`Route::native`), EDGE elements only, must pass `php artisan native:validate`. Write `pressable`, `outlined-text-input`, `select`, `list`, `list-item` as BARE tags; pass handler arguments as `{{ $int }}` (never a literal call, never a quoted string); never run `native:run` or `native:jump`.
- Commits: end every message with the trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Never push. Run `vendor/bin/pint --dirty --format agent` before each commit (root for backend, `mobile/` for app files).
- Before editing under `app/Actions/**`, `app/Http/Resources/**`, `app/Support/**`, `app/Models/**` read the rule files `.ai/rules/index.md` maps to those paths.
- Backend tests run in the app container: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --compact <file>'`. App tests run in `mobile/`: `vendor/bin/pest <file>`. After `git checkout` of a mobile view run `php artisan view:clear` in `mobile/`.

## Review Focus

Inputs and conditions the spec implies that no single task's happy path exercises; each has a test in the named task.

1. A verification code brute-forced by repeated guesses: five wrong codes must clear the code, and the sixth try with the right code must still fail until a resend (Task 2, Task 4).
2. Another subscriber's channel id on any write endpoint: always 404, never a read or a change (Task 4).
3. A code that could not be sent (Apprise unreachable or refusing): no channel row may remain, and a Free account at its limit must send nothing at all (Task 4).
4. A secret typed into the form (webhook URL, bot token): never echoed by the API, never cached, cleared from the form after a successful send (Task 4, Task 8).
5. Deleting a channel that is some alert's only one must be refused with the alert names; a shared channel deletes (Task 4, Task 9).
6. Offline or maintenance during any write: the draft and typed values stay and the message shows beside the buttons (Task 8, Task 9).

---

### Task 1: Setup-field descriptors on the channel types

**Files:**
- Create: `app/Support/ChannelTypes/ChannelSetupField.php`
- Create: `app/Support/ChannelTypes/Concerns/NotCreatableInApp.php`
- Modify: `app/Support/ChannelTypes/Contracts/ChannelType.php`
- Modify: `app/Support/ChannelTypes/ChannelTypeRegistry.php`
- Modify: `app/Support/ChannelTypes/{Email,Ntfy,Discord,Slack,Telegram,Teams}ChannelType.php`
- Modify: `app/Support/ChannelTypes/{Pushover,Webhook,WebPush,MobilePush}ChannelType.php`
- Test: `tests/Feature/ChannelSetupFieldsTest.php`

**Interfaces:**
- Produces: `ChannelSetupField` (`key`, `label`, `input` in `text|email|url|secret`, `required`, `help`, `placeholder`, `default`, `rules`; `toArray()` without `rules`); `ChannelType::appSetupFields(User $user): ?array` (null = not creatable in the app); `ChannelTypeRegistry::creatableInApp(User $user): Collection<int, ChannelType>`; `DiscordChannelType::WEBHOOK_URL_PATTERN`, `SlackChannelType::WEBHOOK_URL_PATTERN`, `TeamsChannelType::workflowUrlRule(): Closure`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ChannelSetupFieldsTest.php`:

```php
<?php

use App\Models\User;
use App\Support\ChannelTypes\ChannelSetupField;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use Illuminate\Support\Facades\Validator;

/**
 * Whether the type's app descriptors accept the input, validated the way the API validates it.
 *
 * @param  array<string, mixed>  $input
 */
function setupFieldsAccept(string $slug, array $input): bool
{
    $fields = app(ChannelTypeRegistry::class)->find($slug)->appSetupFields(User::factory()->make());
    $rules = [];

    foreach ($fields as $field) {
        $rules[$field->key] = [$field->required ? 'required' : 'nullable', 'string', ...$field->rules];
    }

    return ! Validator::make($input, $rules)->fails();
}

test('exactly six types can be created in the app', function () {
    $user = User::factory()->make();
    $registry = app(ChannelTypeRegistry::class);

    expect($registry->creatableInApp($user)->map->slug()->all())
        ->toEqualCanonicalizing(['email', 'ntfy', 'discord', 'slack', 'telegram', 'msteams']);

    foreach (['pushover', 'webhook', 'webpush', 'mobile_push'] as $slug) {
        expect($registry->find($slug)->appSetupFields($user))->toBeNull();
    }
});

test('each type accepts a valid setup and refuses an invalid one', function (string $slug, array $valid, array $invalid) {
    foreach ($valid as $input) {
        expect(setupFieldsAccept($slug, $input))->toBeTrue(json_encode($input));
    }

    foreach ($invalid as $input) {
        expect(setupFieldsAccept($slug, $input))->toBeFalse(json_encode($input));
    }
})->with([
    'email' => ['email', [['to' => 'pat@example.com']], [['to' => 'not-an-email'], ['to' => '']]],
    'ntfy' => ['ntfy', [['topic' => 'wx-abc_123'], ['topic' => 'wx', 'server' => 'https://ntfy.example.com']], [['topic' => 'has space'], ['topic' => ''], ['topic' => 'wx', 'server' => 'notaurl']]],
    'discord' => ['discord', [['webhook_url' => 'https://discord.com/api/webhooks/123456789/abcDEF-token_123']], [['webhook_url' => 'https://example.com/hook'], ['webhook_url' => 'http://discord.com/api/webhooks/1/a'], ['webhook_url' => '']]],
    'slack' => ['slack', [['webhook_url' => 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX']], [['webhook_url' => 'https://hooks.slack.com/services/T0/B0'], ['webhook_url' => 'https://example.com/services/a/b/c']]],
    'telegram' => ['telegram', [['bot_token' => '123456:ABC-def', 'chat_id' => '-1001234']], [['bot_token' => '123456:ABC-def'], ['chat_id' => '-1001234']]],
    'teams' => ['msteams', [['webhook_url' => 'https://prod-12.westus.logic.azure.com:443/workflows/0123456789abcdef0123456789abcdef/triggers/manual/paths/invoke?api-version=2016-06-01&sig=abc_DEF-123']], [['webhook_url' => 'https://example.com/workflows/0123456789abcdef0123456789abcdef/triggers?sig=abc'], ['webhook_url' => 'https://outlook.office.com/webhook/abc']]],
]);

test('the email address defaults to the subscriber\'s own', function () {
    $user = User::factory()->make(['email' => 'pat@example.com']);

    $fields = app(ChannelTypeRegistry::class)->find('email')->appSetupFields($user);

    expect($fields[0]->default)->toBe('pat@example.com')
        ->and($fields[0]->input)->toBe(ChannelSetupField::EMAIL);
});

test('a descriptor publishes everything except its rules, and webhook urls and bot tokens are secrets', function () {
    $user = User::factory()->make();
    $registry = app(ChannelTypeRegistry::class);

    expect(array_keys($registry->find('discord')->appSetupFields($user)[0]->toArray()))
        ->toBe(['key', 'label', 'input', 'required', 'help', 'placeholder', 'default']);

    foreach (['discord' => 0, 'slack' => 0, 'msteams' => 0, 'telegram' => 0] as $slug => $index) {
        expect($registry->find($slug)->appSetupFields($user)[$index]->input)->toBe(ChannelSetupField::SECRET);
    }
});
```

- [ ] **Step 2: Run it to see it fail**

Run the test file with the backend command. Expected: FAIL (`appSetupFields` undefined / class `ChannelSetupField` not found).

- [ ] **Step 3: Implement**

Create `app/Support/ChannelTypes/ChannelSetupField.php`:

```php
<?php

namespace App\Support\ChannelTypes;

/**
 * One field of a channel type's setup, described without Filament so the mobile API can publish it
 * and validate with it. `rules` are Laravel validation rules and are never published.
 */
final class ChannelSetupField
{
    public const TEXT = 'text';

    public const EMAIL = 'email';

    public const URL = 'url';

    /** A value that acts as a password (a bot token, a webhook URL): masked while typed. */
    public const SECRET = 'secret';

    /**
     * @param  list<mixed>  $rules
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $input = self::TEXT,
        public readonly bool $required = true,
        public readonly ?string $help = null,
        public readonly ?string $placeholder = null,
        public readonly ?string $default = null,
        public readonly array $rules = [],
    ) {}

    /**
     * @return array{key: string, label: string, input: string, required: bool, help: ?string, placeholder: ?string, default: ?string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'input' => $this->input,
            'required' => $this->required,
            'help' => $this->help,
            'placeholder' => $this->placeholder,
            'default' => $this->default,
        ];
    }
}
```

Create `app/Support/ChannelTypes/Concerns/NotCreatableInApp.php`:

```php
<?php

namespace App\Support\ChannelTypes\Concerns;

use App\Models\User;

trait NotCreatableInApp
{
    /**
     * @return null
     */
    public function appSetupFields(User $user): ?array
    {
        return null;
    }
}
```

In `Contracts/ChannelType.php` add `use App\Models\User;` and `use App\Support\ChannelTypes\ChannelSetupField;`, and before `transport()`:

```php
    /**
     * The setup fields the mobile app can ask for, described without Filament, or null when the app
     * cannot create this type (its setup needs a browser, a long list of options, or the app itself).
     *
     * @return list<ChannelSetupField>|null
     */
    public function appSetupFields(User $user): ?array;
```

In `ChannelTypeRegistry.php` add `use App\Models\User;` and, after `forPicker()`:

```php
    /**
     * The picker's types the mobile app can create, in the catalog's order.
     *
     * @return Collection<int, ChannelType>
     */
    public function creatableInApp(User $user): Collection
    {
        return $this->forPicker()
            ->map(fn (array $row): ChannelType => $row['type'])
            ->filter(fn (ChannelType $type): bool => $type->appSetupFields($user) !== null)
            ->values();
    }
```

For `PushoverChannelType`, `WebhookChannelType`, `WebPushChannelType`, `MobilePushChannelType`: add `use App\Support\ChannelTypes\Concerns\NotCreatableInApp;` and `use NotCreatableInApp;` inside the class body (next to any existing `use` of traits).

`EmailChannelType` (add `use App\Models\User;`), after `formSchema()`:

```php
    public function appSetupFields(User $user): ?array
    {
        return [
            new ChannelSetupField('to', 'Email address', ChannelSetupField::EMAIL, default: $user->email, rules: ['email', 'max:255']),
        ];
    }
```

`NtfyChannelType` (add `use App\Models\User;`):

```php
    public function appSetupFields(User $user): ?array
    {
        return [
            new ChannelSetupField('topic', 'Topic', help: 'A short, hard-to-guess name — anyone who knows it can subscribe. E.g. "wxnotify-a1b2c3".', rules: ['alpha_dash', 'max:255']),
            new ChannelSetupField('server', 'Server (optional)', ChannelSetupField::URL, required: false, help: 'Leave blank to use the public ntfy.sh server, or enter your own self-hosted server URL.', rules: ['url', 'max:2048']),
        ];
    }
```

`DiscordChannelType` (add `use App\Models\User;`): add the constant and use it in both places.

```php
    public const WEBHOOK_URL_PATTERN = '#^https://discord(?:app)?\.com/api/webhooks/\d+/[\w-]+/?$#';
```

Change the Filament line to `->rule('regex:'.self::WEBHOOK_URL_PATTERN)` (replacing the inline `'regex:#^https://discord…#'` string), and add:

```php
    public function appSetupFields(User $user): ?array
    {
        return [
            new ChannelSetupField('webhook_url', 'Webhook URL', ChannelSetupField::SECRET, help: 'From your Discord channel: Edit Channel → Integrations → Webhooks.', rules: ['url', 'max:2048', 'regex:'.self::WEBHOOK_URL_PATTERN]),
        ];
    }
```

`SlackChannelType` (add `use App\Models\User;`):

```php
    public const WEBHOOK_URL_PATTERN = '#^https://hooks\.slack\.com/services/([\w-]+)/([\w-]+)/([\w-]+)/?$#';
```

Filament line becomes `->rule('regex:'.self::WEBHOOK_URL_PATTERN)`; add:

```php
    public function appSetupFields(User $user): ?array
    {
        return [
            new ChannelSetupField('webhook_url', 'Incoming Webhook URL', ChannelSetupField::SECRET, help: 'From Slack: Apps → Incoming Webhooks → Add New Webhook to Workspace.', rules: ['url', 'max:2048', 'regex:'.self::WEBHOOK_URL_PATTERN]),
        ];
    }
```

`TelegramChannelType` (add `use App\Models\User;`):

```php
    public function appSetupFields(User $user): ?array
    {
        return [
            new ChannelSetupField('bot_token', 'Bot token', ChannelSetupField::SECRET, help: 'Create a bot with @BotFather on Telegram to get a token.', rules: ['max:255']),
            new ChannelSetupField('chat_id', 'Chat ID', help: 'Message your bot, then visit api.telegram.org/bot<token>/getUpdates to find your chat ID.', rules: ['max:255']),
        ];
    }
```

`TeamsChannelType` (add `use App\Models\User;`; `Closure` is already imported): replace the Filament `->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void { … })` block with `->rule(fn (): Closure => self::workflowUrlRule()),` and add:

```php
    /**
     * The validation rule both the Filament field and the app API apply to a pasted Workflows URL.
     */
    public static function workflowUrlRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (self::parseWorkflowUrl((string) $value) === null) {
                $fail('That is not a Power Automate Workflows webhook URL (https://…logic.azure.com/workflows/…&sig=…).');
            }
        };
    }

    public function appSetupFields(User $user): ?array
    {
        return [
            new ChannelSetupField('webhook_url', 'Workflows webhook URL', ChannelSetupField::SECRET, help: 'In Teams: Workflows → "Post to a channel when a webhook request is received", then copy the HTTP POST URL. Microsoft retired the old Office 365 connector webhooks, so those URLs no longer work.', rules: ['url', 'max:2048', self::workflowUrlRule()]),
        ];
    }
```

- [ ] **Step 4: Run the tests**

Run `tests/Feature/ChannelSetupFieldsTest.php`, then the dashboard regressions `tests/Feature/ChannelTypesTest.php` and `tests/Feature/ChannelConnectionTest.php`. Expected: all PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/ChannelTypes tests/Feature/ChannelSetupFieldsTest.php
git commit -m "Channels: setup-field descriptors for the six types the app can create

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Shared actions, the attempt limit and the page refactor

**Files:**
- Create: `app/Exceptions/ChannelCodeNotSent.php`, `app/Exceptions/ChannelInUse.php`, `app/Exceptions/ChannelManagedByApp.php`
- Create: `app/Actions/Channels/BeginChannelConnection.php`, `app/Actions/Channels/RenameChannel.php`, `app/Actions/Channels/DeleteChannel.php`
- Modify: `app/Actions/Channels/VerifyChannelConfirmationCode.php`, `app/Actions/Channels/DispatchChannelConfirmationCode.php`
- Modify: `app/Filament/Dashboard/Pages/ConnectChannel.php`
- Test: `tests/Feature/BeginChannelConnectionTest.php`, `tests/Feature/ChannelCodeAttemptsTest.php`, `tests/Feature/ChannelActionsTest.php`

**Interfaces:**
- Consumes: `ChannelType::buildDestination()`, `DispatchChannelConfirmationCode`, `ChannelDeletionGuard::alertsLeftWithoutChannel()`.
- Produces: `BeginChannelConnection::__invoke(User $user, ChannelType $type, array $data, ?string $label = null): NotificationChannel` (throws `ChannelCodeNotSent`; lets `InvalidArgumentException` from `buildDestination` through); `RenameChannel::__invoke(User $user, NotificationChannel $channel, string $label): NotificationChannel`; `DeleteChannel::__invoke(User $user, NotificationChannel $channel): void` (throws `ChannelInUse`); exceptions with `render()` answering 502 `code_not_sent`, 409 `channel_in_use` (with `alerts`) and 409 `channel_managed_by_app`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/BeginChannelConnectionTest.php`:

```php
<?php

use App\Actions\Channels\BeginChannelConnection;
use App\Exceptions\ChannelCodeNotSent;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->type = app(ChannelTypeRegistry::class)->find('ntfy');
});

test('it stores an unverified channel with the type\'s label and sends the code', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $channel = app(BeginChannelConnection::class)($this->user, $this->type, ['topic' => 'wx-topic']);

    expect($channel->user_id)->toBe($this->user->id)
        ->and($channel->channel_type)->toBe('ntfy')
        ->and($channel->label)->toBe($this->type->label())
        ->and($channel->apprise_url)->toBe('ntfy://wx-topic')
        ->and($channel->isVerified())->toBeFalse()
        ->and($channel->confirmation_code)->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/notify/'));
});

test('a label given by the subscriber wins', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $channel = app(BeginChannelConnection::class)($this->user, $this->type, ['topic' => 'wx-topic'], 'Phone alerts');

    expect($channel->label)->toBe('Phone alerts');
});

test('when the code cannot be sent the new channel is removed again', function () {
    Http::fake(['*/notify/*' => Http::failedConnection()]);

    expect(fn () => app(BeginChannelConnection::class)($this->user, $this->type, ['topic' => 'wx-topic']))
        ->toThrow(ChannelCodeNotSent::class);

    expect(NotificationChannel::where('user_id', $this->user->id)->count())->toBe(0);
});
```

`tests/Feature/ChannelCodeAttemptsTest.php`:

```php
<?php

use App\Actions\Channels\DispatchChannelConfirmationCode;
use App\Actions\Channels\VerifyChannelConfirmationCode;
use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->channel = NotificationChannel::factory()->unverified()->create(); // code 123456
    $this->verify = app(VerifyChannelConfirmationCode::class);
});

test('the right code verifies and clears the count', function () {
    expect(($this->verify)($this->channel->fresh(), '000000'))->toBeFalse()
        ->and(($this->verify)($this->channel->fresh(), '123456'))->toBeTrue()
        ->and($this->channel->fresh()->isVerified())->toBeTrue();
});

test('five wrong codes clear the stored code, so even the right one fails afterwards', function () {
    foreach (range(1, 5) as $_) {
        expect(($this->verify)($this->channel->fresh(), '000000'))->toBeFalse();
    }

    expect($this->channel->fresh()->confirmation_code)->toBeNull()
        ->and(($this->verify)($this->channel->fresh(), '123456'))->toBeFalse()
        ->and($this->channel->fresh()->isVerified())->toBeFalse();
});

test('four wrong codes leave the code usable', function () {
    foreach (range(1, 4) as $_) {
        ($this->verify)($this->channel->fresh(), '000000');
    }

    expect(($this->verify)($this->channel->fresh(), '123456'))->toBeTrue();
});

test('sending a new code resets the count', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    foreach (range(1, 4) as $_) {
        ($this->verify)($this->channel->fresh(), '000000');
    }

    app(DispatchChannelConfirmationCode::class)($this->channel->fresh());
    $fresh = $this->channel->fresh();

    foreach (range(1, 4) as $_) {
        expect(($this->verify)($fresh->fresh(), '000000'))->toBeFalse();
    }

    expect(($this->verify)($fresh->fresh(), $fresh->confirmation_code))->toBeTrue();
});
```

`tests/Feature/ChannelActionsTest.php`:

```php
<?php

use App\Actions\Channels\DeleteChannel;
use App\Actions\Channels\RenameChannel;
use App\Exceptions\ChannelInUse;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->channel = NotificationChannel::factory()->for($this->user)->create(['label' => 'Old']);
});

test('renaming changes the label and logs the subscriber as the causer', function () {
    app(RenameChannel::class)($this->user, $this->channel, 'New');

    expect($this->channel->fresh()->label)->toBe('New')
        ->and(Activity::query()->where('subject_type', NotificationChannel::class)->latest('id')->first()->causer_id)->toBe($this->user->id);
});

test('a channel that is an alert\'s only one cannot be deleted, and the alert is named', function () {
    $rule = AlertRule::factory()->for($this->user)->create(['name' => 'Storm warnings']);
    $rule->notificationChannels()->attach($this->channel);

    try {
        app(DeleteChannel::class)($this->user, $this->channel);
        $this->fail('Expected ChannelInUse');
    } catch (ChannelInUse $e) {
        expect($e->alertNames)->toBe(['Storm warnings']);
    }

    expect(NotificationChannel::find($this->channel->id))->not->toBeNull();
});

test('a channel shared with another channel on every alert it serves is deleted', function () {
    $rule = AlertRule::factory()->for($this->user)->create();
    $other = NotificationChannel::factory()->for($this->user)->create();
    $rule->notificationChannels()->attach([$this->channel->id, $other->id]);

    app(DeleteChannel::class)($this->user, $this->channel);

    expect(NotificationChannel::find($this->channel->id))->toBeNull();
});
```

- [ ] **Step 2: Run them to see them fail** (classes not found).

- [ ] **Step 3: Implement**

`app/Exceptions/ChannelCodeNotSent.php`:

```php
<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The confirmation code could not be delivered (the destination refused it or the notification
 * service is unreachable).
 */
class ChannelCodeNotSent extends RuntimeException implements ShouldntReport
{
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'code_not_sent',
            'message' => 'We couldn\'t send the confirmation code. Please try again in a moment.',
        ], 502);
    }
}
```

`app/Exceptions/ChannelInUse.php`:

```php
<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The channel is some alert's only one; deleting it would leave that alert unable to deliver.
 */
class ChannelInUse extends RuntimeException implements ShouldntReport
{
    private const NAMED_ALERTS = 5;

    /**
     * @param  list<string>  $alertNames
     */
    public function __construct(public readonly string $channelLabel, public readonly array $alertNames)
    {
        parent::__construct('The channel is the only one for some alerts.');
    }

    public function render(): JsonResponse
    {
        $names = collect($this->alertNames);
        $listed = $names->take(self::NAMED_ALERTS)->implode(', ');
        $rest = $names->count() - self::NAMED_ALERTS;

        return response()->json([
            'error' => 'channel_in_use',
            'message' => "Can't delete {$this->channelLabel}. It's the only channel for: ".($rest > 0 ? "{$listed} (and {$rest} more)" : $listed).'. Add another channel to those alerts first.',
            'alerts' => $names->all(),
        ], 409);
    }
}
```

`app/Exceptions/ChannelManagedByApp.php`:

```php
<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A phone's push channel is created and removed by the app's own push registration, never edited here.
 */
class ChannelManagedByApp extends RuntimeException implements ShouldntReport
{
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'channel_managed_by_app',
            'message' => 'A phone\'s push notifications are turned on or off in the app\'s Account screen.',
        ], 409);
    }
}
```

`app/Actions/Channels/BeginChannelConnection.php` (this is the body that sat in `ConnectChannel`):

```php
<?php

namespace App\Actions\Channels;

use App\Exceptions\ChannelCodeNotSent;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Illuminate\Support\Arr;

/**
 * The one way a channel is created and sent its first confirmation code, for the dashboard wizard
 * and the mobile API. Stores the channel unverified, sends the code and, when it cannot be sent,
 * removes a channel it just created (nothing else could retry it, and a retry would add a duplicate
 * row every time). A channel that already existed is only ever refreshed, never deleted.
 */
class BeginChannelConnection
{
    /**
     * @param  array<string, mixed>  $data  the setup fields (and anything else the form held)
     *
     * @throws \InvalidArgumentException when the type cannot build a destination from the data
     * @throws ChannelCodeNotSent
     */
    public function __invoke(User $user, ChannelType $type, array $data, ?string $label = null): NotificationChannel
    {
        $destination = $type->buildDestination($data);
        $existing = $this->existingWebPushChannel($user, $type, $destination);
        $channel = $this->storePendingChannel($user, $type, $destination, $existing, $label);

        if (! app(DispatchChannelConfirmationCode::class)($channel)) {
            if ($existing === null) {
                $channel->delete();
            }

            throw new ChannelCodeNotSent;
        }

        return $channel;
    }

    /**
     * The subscriber's existing Web Push channel for this exact browser endpoint, if any.
     *
     * The subscribe component deliberately reuses the browser's live PushSubscription, so a
     * subscriber who runs the wizard twice in one browser would otherwise end up with two channels
     * pointing at one endpoint and receive every alert twice. `config` is encrypted, so the endpoint
     * is only comparable after decryption.
     *
     * @param  array<string, mixed>  $destination
     */
    private function existingWebPushChannel(User $user, ChannelType $type, array $destination): ?NotificationChannel
    {
        $endpoint = $destination['config']['endpoint'] ?? null;

        if ($type->slug() !== 'webpush' || ! is_string($endpoint)) {
            return null;
        }

        return NotificationChannel::query()
            ->where('user_id', $user->id)
            ->where('channel_type', 'webpush')
            ->get()
            ->first(fn (NotificationChannel $channel): bool => ($channel->config['endpoint'] ?? null) === $endpoint);
    }

    /**
     * Refresh the given channel with the newly built destination, or create one when there is
     * nothing to refresh. A refreshed channel goes back to unverified: its keys just changed.
     *
     * @param  array<string, mixed>  $destination
     */
    private function storePendingChannel(User $user, ChannelType $type, array $destination, ?NotificationChannel $existing, ?string $label): NotificationChannel
    {
        $attributes = [
            'label' => $label ?? $destination['label'] ?? $type->label(),
            ...Arr::only($destination, ['apprise_url', 'config']),
        ];

        if ($existing !== null) {
            $existing->forceFill([...$attributes, 'verified_at' => null])->save();

            return $existing;
        }

        return NotificationChannel::create([
            'user_id' => $user->id,
            'channel_type' => $type->slug(),
            ...$attributes,
        ]);
    }
}
```

`app/Actions/Channels/RenameChannel.php`:

```php
<?php

namespace App\Actions\Channels;

use App\Models\NotificationChannel;
use App\Models\User;
use Spatie\Activitylog\Support\CauserResolver;

class RenameChannel
{
    public function __invoke(User $user, NotificationChannel $channel, string $label): NotificationChannel
    {
        app(CauserResolver::class)->withCauser($user, fn () => $channel->update(['label' => trim($label)]));

        return $channel;
    }
}
```

`app/Actions/Channels/DeleteChannel.php`:

```php
<?php

namespace App\Actions\Channels;

use App\Exceptions\ChannelInUse;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelDeletionGuard;
use App\Support\Units;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Deletes a channel unless it is some alert's only one (the dashboard's ChannelDeletionGuard:
 * deleting it would leave that alert unable to deliver).
 */
class DeleteChannel
{
    /**
     * @throws ChannelInUse
     */
    public function __invoke(User $user, NotificationChannel $channel): void
    {
        $alerts = ChannelDeletionGuard::alertsLeftWithoutChannel($channel);

        if ($alerts->isNotEmpty()) {
            $units = Units::for($user)->system;

            throw new ChannelInUse($channel->label, $alerts->map(fn (AlertRule $alert): string => $alert->displayName($units))->values()->all());
        }

        app(CauserResolver::class)->withCauser($user, fn () => $channel->delete());
    }
}
```

Replace `app/Actions/Channels/VerifyChannelConfirmationCode.php` with:

```php
<?php

namespace App\Actions\Channels;

use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Cache;

class VerifyChannelConfirmationCode
{
    /** A six-digit code is guessable, so this many wrong tries clear it and a resend is required. */
    public const MAX_WRONG_CODES = 5;

    public static function attemptsKey(NotificationChannel $channel): string
    {
        return "channel-code-attempts:{$channel->id}";
    }

    public function __invoke(NotificationChannel $channel, string $code): bool
    {
        if ($channel->isVerified()) {
            return true;
        }

        if (blank($channel->confirmation_code)) {
            return false;
        }

        if (! hash_equals((string) $channel->confirmation_code, $code)) {
            $this->recordWrongCode($channel);

            return false;
        }

        if (! $channel->confirmation_code_expires_at || $channel->confirmation_code_expires_at->isPast()) {
            return false;
        }

        $channel->forceFill([
            'verified_at' => now(),
            'confirmation_code' => null,
            'confirmation_code_expires_at' => null,
        ])->save();

        Cache::forget(self::attemptsKey($channel));

        return true;
    }

    private function recordWrongCode(NotificationChannel $channel): void
    {
        $key = self::attemptsKey($channel);

        Cache::add($key, 0, now()->addMinutes(30));

        if (Cache::increment($key) < self::MAX_WRONG_CODES) {
            return;
        }

        Cache::forget($key);
        $channel->forceFill(['confirmation_code' => null, 'confirmation_code_expires_at' => null])->save();
    }
}
```

In `DispatchChannelConfirmationCode.php` add `use Illuminate\Support\Facades\Cache;` and, as the first statement of `__invoke` (before `$code = …`):

```php
        Cache::forget(VerifyChannelConfirmationCode::attemptsKey($channel));
```

In `app/Filament/Dashboard/Pages/ConnectChannel.php`: replace the whole `->afterValidation(function (Get $get, Set $set) { … })` closure of the "Set it up" step with:

```php
                        ->afterValidation(function (Get $get, Set $set) {
                            $type = app(ChannelTypeRegistry::class)->find($get('channel_type'));

                            try {
                                $channel = app(BeginChannelConnection::class)(auth()->user(), $type, $get());
                            } catch (\InvalidArgumentException $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();

                                throw new Halt;
                            } catch (ChannelCodeNotSent) {
                                Notification::make()
                                    ->title('Couldn\'t send your confirmation code')
                                    ->body('The notification service is temporarily unavailable. Please try again in a moment.')
                                    ->danger()
                                    ->send();

                                throw new Halt;
                            }

                            $set('channel_id', $channel->id);
                        }),
```

Delete the private methods `existingWebPushChannel()` and `storePendingChannel()` from the page, and fix its imports: add `use App\Actions\Channels\BeginChannelConnection;` and `use App\Exceptions\ChannelCodeNotSent;`; remove `DispatchChannelConfirmationCode`, `ChannelType` (the contract), and `Arr` imports if nothing else in the file uses them (`NotificationChannel` stays: `connect()` uses it).

- [ ] **Step 4: Run the tests**

Run the three new files, then the regressions that exercise the refactored page and the verify action: `tests/Feature/ChannelConnectionTest.php`, `tests/Feature/WebPushChannelTest.php`, `tests/Feature/NotificationChannelResourceTest.php`, `tests/Feature/ChannelDeletionGuardTest.php`. Expected: all PASS, with no change to the existing tests.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Exceptions app/Actions/Channels app/Filament/Dashboard/Pages/ConnectChannel.php tests/Feature/BeginChannelConnectionTest.php tests/Feature/ChannelCodeAttemptsTest.php tests/Feature/ChannelActionsTest.php
git commit -m "Channels: BeginChannelConnection shared by the wizard and the API, attempt-limited code check, rename and delete actions

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Read endpoints (`GET /channel-types`, `GET /channels`)

**Files:**
- Create: `app/Http/Resources/Api/V1/ChannelResource.php`, `app/Http/Resources/Api/V1/ChannelTypeResource.php`
- Create: `app/Http/Controllers/Api/V1/ChannelController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/ApiChannelsTest.php`

**Interfaces:**
- Consumes: `ChannelTypeRegistry::creatableInApp()`, `ChannelType::appSetupFields()`, `User::canAddChannel()`.
- Produces: `GET /api/v1/channel-types` → `data: [{slug, label, can_add, unavailable_reason: 'channel_limit'|null, fields: [{key,label,input,required,help,placeholder,default}]}]`; `GET /api/v1/channels` → `data: [{id, label, channel_type, type_label, destination: ?string, destination_label: ?string, verified: bool, managed_by_app: bool, created_at}]` oldest first. Route names `api.v1.channel-types`, `api.v1.channels.index`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\Tier;
use App\Models\NotificationChannel;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'pat@example.com', 'tier_override' => Tier::Free]);
    $this->phone = $this->user->createToken('Pixel 9', ['app']);
});

function apiChannelCall($test, string $method, string $uri, array $body = [], ?string $token = null)
{
    app('auth')->forgetGuards();

    return $test->withToken($token ?? $test->phone->plainTextToken)->json($method, $uri, $body);
}

test('the channel endpoints need an app token', function () {
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/channels')->assertUnauthorized();

    $beacon = $this->user->createToken('beacon', ['location'])->plainTextToken;
    apiChannelCall($this, 'GET', '/api/v1/channel-types', [], $beacon)->assertForbidden();
});

test('the types are the six the app can create, with their fields and the email defaulted to the subscriber\'s own', function () {
    $response = apiChannelCall($this, 'GET', '/api/v1/channel-types')->assertOk();

    $types = collect($response->json('data'))->keyBy('slug');

    expect($types->keys()->all())->toEqualCanonicalizing(['email', 'ntfy', 'discord', 'slack', 'telegram', 'msteams'])
        ->and($types['email']['fields'][0])->toBe([
            'key' => 'to', 'label' => 'Email address', 'input' => 'email', 'required' => true,
            'help' => null, 'placeholder' => null, 'default' => 'pat@example.com',
        ])
        ->and($types['discord']['fields'][0]['input'])->toBe('secret')
        ->and($types['ntfy']['fields'][1]['required'])->toBeFalse()
        ->and($types['discord'])->toHaveKeys(['label', 'can_add', 'unavailable_reason'])
        ->and($response->getContent())->not->toContain('regex');
});

test('a Free subscriber who used the no-cost slot cannot add another type but can still add email', function () {
    NotificationChannel::factory()->for($this->user)->create(['channel_type' => 'discord']);

    $types = collect(apiChannelCall($this, 'GET', '/api/v1/channel-types')->json('data'))->keyBy('slug');

    expect($types['slack']['can_add'])->toBeFalse()
        ->and($types['slack']['unavailable_reason'])->toBe('channel_limit')
        ->and($types['email']['can_add'])->toBeTrue()
        ->and($types['email']['unavailable_reason'])->toBeNull();
});

test('the list is the subscriber\'s own channels, oldest first, of every type, and never carries a destination url, config or token', function () {
    $discord = NotificationChannel::factory()->for($this->user)->create(['label' => 'Team Discord', 'apprise_url' => 'discord://123456789/SECRETTOKEN1234/']);
    $email = NotificationChannel::factory()->for($this->user)->email('pat@example.com')->unverified()->create(['label' => 'My email']);
    $phone = NotificationChannel::factory()->for($this->user)->mobilePush('fcm-secret-token')->create();
    NotificationChannel::factory()->create(['label' => 'Theirs']);

    $response = apiChannelCall($this, 'GET', '/api/v1/channels')->assertOk();
    $rows = collect($response->json('data'))->keyBy('id');

    expect($rows->keys()->all())->toBe([$discord->id, $email->id, $phone->id])
        ->and($rows[$discord->id])->toMatchArray([
            'label' => 'Team Discord', 'channel_type' => 'discord', 'type_label' => 'Discord',
            'destination' => '••••1234', 'destination_label' => 'Discord webhook', 'verified' => true, 'managed_by_app' => false,
        ])
        ->and($rows[$email->id]['verified'])->toBeFalse()
        ->and($rows[$phone->id]['managed_by_app'])->toBeTrue()
        ->and($response->getContent())->not->toContain('SECRETTOKEN')
        ->not->toContain('fcm-secret-token')
        ->not->toContain('apprise_url')
        ->not->toContain('config');
});
```

- [ ] **Step 2: Run it to see it fail** (404 on the routes).

- [ ] **Step 3: Implement**

`app/Http/Resources/Api/V1/ChannelResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use App\Models\NotificationChannel;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A channel as the app's list and screen need it. Never carries `apprise_url`, `config`, a URL, a
 * token or a confirmation code: `destination` is the type's masked summary.
 *
 * @property NotificationChannel $resource
 */
class ChannelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $channel = $this->resource;
        $type = app(ChannelTypeRegistry::class)->find($channel->channel_type);
        $summary = $type?->displaySummary($channel);
        $hasSummary = $summary !== null && $summary !== '—';

        return [
            'id' => $channel->id,
            'label' => $channel->label,
            'channel_type' => $channel->channel_type,
            'type_label' => $type?->label() ?? $channel->channel_type,
            'destination' => $hasSummary ? $summary : null,
            'destination_label' => $hasSummary ? $type->destinationLabel() : null,
            'verified' => $channel->isVerified(),
            'managed_by_app' => $channel->channel_type === 'mobile_push',
            'created_at' => $channel->created_at?->toIso8601String(),
        ];
    }
}
```

`app/Http/Resources/Api/V1/ChannelTypeResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Support\ChannelTypes\ChannelSetupField;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A channel type the app can create. Wraps `['type' => ChannelType, 'user' => User]`; the fields are
 * the descriptors without their validation rules.
 */
class ChannelTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ChannelType $type */
        $type = $this->resource['type'];
        /** @var User $user */
        $user = $this->resource['user'];
        $canAdd = $user->canAddChannel($type->slug());

        return [
            'slug' => $type->slug(),
            'label' => $type->label(),
            'can_add' => $canAdd,
            'unavailable_reason' => $canAdd ? null : 'channel_limit',
            'fields' => array_map(fn (ChannelSetupField $field): array => $field->toArray(), $type->appSetupFields($user) ?? []),
        ];
    }
}
```

`app/Http/Controllers/Api/V1/ChannelController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ChannelResource;
use App\Http\Resources\Api\V1\ChannelTypeResource;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ChannelController extends Controller
{
    public function types(Request $request, ChannelTypeRegistry $registry): AnonymousResourceCollection
    {
        $user = $request->user();

        return ChannelTypeResource::collection(
            $registry->creatableInApp($user)->map(fn (ChannelType $type): array => ['type' => $type, 'user' => $user]),
        );
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return ChannelResource::collection($request->user()->notificationChannels()->orderBy('id')->get());
    }
}
```

In `routes/api.php` add `use App\Http\Controllers\Api\V1\ChannelController;` (alphabetical, after `AppTokenController`) and, inside the app-token group after the `locations` routes:

```php
        Route::get('/channel-types', [ChannelController::class, 'types'])->name('api.v1.channel-types');
        Route::get('/channels', [ChannelController::class, 'index'])->name('api.v1.channels.index');
```

- [ ] **Step 4: Run the test** (`tests/Feature/ApiChannelsTest.php`). Expected: PASS. If the masked Discord summary differs, `maskLast4` shows `'••••'` plus the last four characters of the token: the factory URL `discord://123456789/SECRETTOKEN1234/` gives `••••1234`.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http routes/api.php tests/Feature/ApiChannelsTest.php
git commit -m "API: GET /channel-types and GET /channels

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Write endpoints (create, verify, resend, test, rename, delete)

**Files:**
- Create: `app/Http/Requests/StoreChannelRequest.php`, `VerifyChannelRequest.php`, `RenameChannelRequest.php`
- Modify: `app/Http/Controllers/Api/V1/ChannelController.php` (full replacement below)
- Modify: `routes/api.php`, `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/ApiChannelWritesTest.php`

**Interfaces:**
- Consumes: Task 2's actions and exceptions; Task 3's resources and `apiChannelCall()` is NOT shared (each test file defines its own helper; this file defines `apiChannelWriteCall`).
- Produces: `POST /channels`, `POST /channels/{id}/verify`, `POST /channels/{id}/resend`, `POST /channels/{id}/test`, `PUT /channels/{id}`, `DELETE /channels/{id}` with the status codes and error codes of the spec; limiter `app-channel-send`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Actions\Channels\VerifyChannelConfirmationCode;
use App\Enums\Tier;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $this->user = User::factory()->create(['tier_override' => Tier::Free]);
    $this->phone = $this->user->createToken('Pixel 9', ['app']);
});

function apiChannelWriteCall($test, string $method, string $uri, array $body = [])
{
    app('auth')->forgetGuards();

    return $test->withToken($test->phone->plainTextToken)->json($method, $uri, $body);
}

const DISCORD_URL = 'https://discord.com/api/webhooks/123456789/abcDEF-token_123';

test('creating a channel stores it unverified, sends the code, and never echoes the secret', function () {
    $response = apiChannelWriteCall($this, 'POST', '/api/v1/channels', ['channel_type' => 'discord', 'webhook_url' => DISCORD_URL])->assertCreated();

    $channel = NotificationChannel::where('user_id', $this->user->id)->sole();

    expect($channel->apprise_url)->toBe('discord://123456789/abcDEF-token_123/')
        ->and($channel->isVerified())->toBeFalse()
        ->and($channel->confirmation_code)->not->toBeNull()
        ->and($response->json('data.id'))->toBe($channel->id)
        ->and($response->json('data.verified'))->toBeFalse()
        ->and($response->json('data.label'))->toBe('Discord')
        ->and($response->getContent())->not->toContain('abcDEF')
        ->not->toContain($channel->confirmation_code);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/notify/'));
    expect(Activity::query()->where('subject_type', NotificationChannel::class)->where('subject_id', $channel->id)->first()->causer_id)->toBe($this->user->id);
});

test('an email channel and a label given by the subscriber', function () {
    Mail::fake();

    $response = apiChannelWriteCall($this, 'POST', '/api/v1/channels', ['channel_type' => 'email', 'to' => 'pat@example.com', 'label' => 'Work email'])->assertCreated();

    expect($response->json('data.label'))->toBe('Work email')
        ->and(NotificationChannel::where('user_id', $this->user->id)->sole()->config)->toBe(['to' => 'pat@example.com']);
});

test('the fields are validated per type and a type that is unknown or not creatable is refused', function (array $body, string $field) {
    apiChannelWriteCall($this, 'POST', '/api/v1/channels', $body)->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(NotificationChannel::where('user_id', $this->user->id)->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'bad discord url' => [['channel_type' => 'discord', 'webhook_url' => 'https://example.com/hook'], 'webhook_url'],
    'missing discord url' => [['channel_type' => 'discord'], 'webhook_url'],
    'ntfy topic with a space' => [['channel_type' => 'ntfy', 'topic' => 'has space'], 'topic'],
    'telegram without a chat' => [['channel_type' => 'telegram', 'bot_token' => '1:abc'], 'chat_id'],
    'bad email' => [['channel_type' => 'email', 'to' => 'nope'], 'to'],
    'overlong label' => [['channel_type' => 'ntfy', 'topic' => 'wx', 'label' => str_repeat('a', 256)], 'label'],
    'pushover is not creatable' => [['channel_type' => 'pushover', 'user_key' => 'x'], 'channel_type'],
    'webhook is not creatable' => [['channel_type' => 'webhook', 'url' => 'https://example.com'], 'channel_type'],
    'unknown type' => [['channel_type' => 'carrier-pigeon'], 'channel_type'],
]);

test('a Free subscriber at the no-cost limit is refused before anything is sent, but may still add email', function () {
    NotificationChannel::factory()->for($this->user)->create(['channel_type' => 'discord']);

    apiChannelWriteCall($this, 'POST', '/api/v1/channels', ['channel_type' => 'ntfy', 'topic' => 'wx'])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'channel_limit');

    Http::assertNothingSent();
    expect(NotificationChannel::where('user_id', $this->user->id)->count())->toBe(1);

    Mail::fake();
    apiChannelWriteCall($this, 'POST', '/api/v1/channels', ['channel_type' => 'email', 'to' => 'pat@example.com'])->assertCreated();
});

test('when the code cannot be sent the answer is 502 and no channel remains', function (callable $fake) {
    $fake();

    apiChannelWriteCall($this, 'POST', '/api/v1/channels', ['channel_type' => 'ntfy', 'topic' => 'wx'])
        ->assertStatus(502)
        ->assertJsonPath('error', 'code_not_sent');

    expect(NotificationChannel::where('user_id', $this->user->id)->count())->toBe(0);
})->with([
    'unreachable' => [fn () => Http::fake(['*/notify/*' => Http::failedConnection()])],
    'refused' => [fn () => Http::fake(['*/notify/*' => Http::response(status: 500)])],
]);

test('the right code verifies, a wrong one is refused with a message, and verifying twice is fine', function () {
    $channel = NotificationChannel::factory()->for($this->user)->unverified()->create();

    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/verify", ['code' => '000000'])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'invalid_code')
        ->assertJsonPath('message', 'That code didn\'t match, or it expired. Request a new code if you need one.');

    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/verify", ['code' => '123456'])
        ->assertOk()
        ->assertJsonPath('data.verified', true);

    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/verify", ['code' => '999999'])->assertOk();
    expect($channel->fresh()->isVerified())->toBeTrue();
});

test('five wrong codes lock the code until a resend', function () {
    $channel = NotificationChannel::factory()->for($this->user)->unverified()->create();

    foreach (range(1, VerifyChannelConfirmationCode::MAX_WRONG_CODES) as $_) {
        apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/verify", ['code' => '000000'])->assertUnprocessable();
    }

    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/verify", ['code' => '123456'])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'invalid_code');

    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/resend")->assertNoContent();
    $code = $channel->fresh()->confirmation_code;

    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/verify", ['code' => $code])->assertOk();
});

test('resend sends a fresh code, refuses a verified channel, and says when it could not send', function () {
    $channel = NotificationChannel::factory()->for($this->user)->unverified()->create();

    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/resend")->assertNoContent();
    Http::assertSentCount(1);
    expect($channel->fresh()->confirmation_code_expires_at->isFuture())->toBeTrue();

    Http::fake(['*/notify/*' => Http::failedConnection()]);
    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/resend")->assertStatus(502)->assertJsonPath('error', 'code_not_sent');

    $verified = NotificationChannel::factory()->for($this->user)->create();
    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$verified->id}/resend")->assertConflict()->assertJsonPath('error', 'already_verified');
});

test('a test goes to a verified channel only, and says when it failed', function () {
    $verified = NotificationChannel::factory()->for($this->user)->create();
    $unverified = NotificationChannel::factory()->for($this->user)->unverified()->create();

    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$verified->id}/test")->assertNoContent();
    Http::assertSentCount(1);

    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$unverified->id}/test")->assertConflict()->assertJsonPath('error', 'not_verified');
    Http::assertSentCount(1);

    Http::fake(['*/notify/*' => Http::response(status: 500)]);
    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$verified->id}/test")->assertStatus(502)->assertJsonPath('error', 'test_failed');
});

test('renaming changes the label only, validates it, and logs the subscriber', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create(['label' => 'Old', 'apprise_url' => 'discord://1/tok/']);

    apiChannelWriteCall($this, 'PUT', "/api/v1/channels/{$channel->id}", ['label' => 'New'])->assertOk()->assertJsonPath('data.label', 'New');
    expect($channel->fresh()->label)->toBe('New')->and($channel->fresh()->apprise_url)->toBe('discord://1/tok/');

    apiChannelWriteCall($this, 'PUT', "/api/v1/channels/{$channel->id}", ['label' => ''])->assertUnprocessable()->assertJsonValidationErrors('label');
    apiChannelWriteCall($this, 'PUT', "/api/v1/channels/{$channel->id}", ['label' => str_repeat('a', 256)])->assertUnprocessable();

    expect(Activity::query()->where('subject_type', NotificationChannel::class)->where('subject_id', $channel->id)->latest('id')->first()->causer_id)->toBe($this->user->id);
});

test('a channel that is an alert\'s only one is refused with the alert names, a shared one is deleted', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create();
    $rule = AlertRule::factory()->for($this->user)->create(['name' => 'Storm warnings']);
    $rule->notificationChannels()->attach($channel);

    apiChannelWriteCall($this, 'DELETE', "/api/v1/channels/{$channel->id}")
        ->assertConflict()
        ->assertJsonPath('error', 'channel_in_use')
        ->assertJsonPath('alerts', ['Storm warnings']);
    expect(NotificationChannel::find($channel->id))->not->toBeNull();

    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($this->user)->create());

    apiChannelWriteCall($this, 'DELETE', "/api/v1/channels/{$channel->id}")->assertNoContent();
    expect(NotificationChannel::find($channel->id))->toBeNull();
});

test('the phone\'s own push channel is read-only on every write', function (string $method, string $suffix, array $body) {
    $phone = NotificationChannel::factory()->for($this->user)->mobilePush()->create();

    apiChannelWriteCall($this, $method, "/api/v1/channels/{$phone->id}{$suffix}", $body)
        ->assertConflict()
        ->assertJsonPath('error', 'channel_managed_by_app');

    expect(NotificationChannel::find($phone->id))->not->toBeNull();
})->with([
    'rename' => ['PUT', '', ['label' => 'x']],
    'delete' => ['DELETE', '', []],
    'verify' => ['POST', '/verify', ['code' => '123456']],
    'resend' => ['POST', '/resend', []],
    'test' => ['POST', '/test', []],
]);

test('another subscriber\'s channel is a 404 on every write', function (string $method, string $suffix, array $body) {
    $theirs = NotificationChannel::factory()->create(['label' => 'Theirs']);

    apiChannelWriteCall($this, $method, "/api/v1/channels/{$theirs->id}{$suffix}", $body)->assertNotFound();

    expect($theirs->fresh()->label)->toBe('Theirs');
    Http::assertNothingSent();
})->with([
    'rename' => ['PUT', '', ['label' => 'x']],
    'delete' => ['DELETE', '', []],
    'verify' => ['POST', '/verify', ['code' => '123456']],
    'resend' => ['POST', '/resend', []],
    'test' => ['POST', '/test', []],
]);

test('sending is limited to ten an hour per subscriber, shared by create, resend and test', function () {
    $channel = NotificationChannel::factory()->for($this->user)->unverified()->create();
    RateLimiter::clear('app-channel-send:'.$this->user->id);

    foreach (range(1, 10) as $_) {
        apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/resend")->assertNoContent();
    }

    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/resend")->assertStatus(429);
    apiChannelWriteCall($this, 'POST', '/api/v1/channels', ['channel_type' => 'ntfy', 'topic' => 'wx'])->assertStatus(429);
});

test('during maintenance a write answers 503 with the maintenance message as plain text', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create();
    $settings = app(GeneralSettings::class);
    $settings->maintenance_mode = true;
    $settings->maintenance_message = 'Back soon.';
    $settings->save();

    $response = apiChannelWriteCall($this, 'PUT', "/api/v1/channels/{$channel->id}", ['label' => 'New']);

    $response->assertStatus(503);
    expect($response->getContent())->toBe('Back soon.')->and($channel->fresh()->label)->not->toBe('New');
});
```

Note on the limiter test: the limiter key is `app-channel-send:{user id}` (Task 4 Step 3), so `RateLimiter::clear()` with that key resets it between tests; the throttle middleware's own key is the limiter name concatenated with the `by()` value, so use `Cache::flush()` in the test instead if `clear()` does not reset it (run it and check).

- [ ] **Step 2: Run it to see it fail** (404 / 405 on the new routes).

- [ ] **Step 3: Implement**

`app/Http/Requests/StoreChannelRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new channel from the chosen type's own setup-field descriptors, so the rules are the
 * ones the dashboard form applies (kept in step by ChannelApiParityTest).
 */
class StoreChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creatable = app(ChannelTypeRegistry::class)->creatableInApp($this->user());

        $rules = [
            'channel_type' => ['required', 'string', Rule::in($creatable->map->slug()->all())],
            'label' => ['nullable', 'string', 'max:255'],
        ];

        $type = $creatable->first(fn (ChannelType $type): bool => $type->slug() === $this->input('channel_type'));

        foreach ($type?->appSetupFields($this->user()) ?? [] as $field) {
            $rules[$field->key] = [$field->required ? 'required' : 'nullable', 'string', ...$field->rules];
        }

        return $rules;
    }
}
```

`app/Http/Requests/VerifyChannelRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:20']];
    }
}
```

`app/Http/Requests/RenameChannelRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RenameChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['label' => ['required', 'string', 'max:255']];
    }
}
```

Replace `app/Http/Controllers/Api/V1/ChannelController.php` with:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Channels\BeginChannelConnection;
use App\Actions\Channels\DeleteChannel;
use App\Actions\Channels\DispatchChannelConfirmationCode;
use App\Actions\Channels\RenameChannel;
use App\Actions\Channels\SendTestNotification;
use App\Actions\Channels\VerifyChannelConfirmationCode;
use App\Exceptions\ChannelCodeNotSent;
use App\Exceptions\ChannelManagedByApp;
use App\Http\Controllers\Controller;
use App\Http\Requests\RenameChannelRequest;
use App\Http\Requests\StoreChannelRequest;
use App\Http\Requests\VerifyChannelRequest;
use App\Http\Resources\Api\V1\ChannelResource;
use App\Http\Resources\Api\V1\ChannelTypeResource;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\Activitylog\Support\CauserResolver;

class ChannelController extends Controller
{
    public function types(Request $request, ChannelTypeRegistry $registry): AnonymousResourceCollection
    {
        $user = $request->user();

        return ChannelTypeResource::collection(
            $registry->creatableInApp($user)->map(fn (ChannelType $type): array => ['type' => $type, 'user' => $user]),
        );
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return ChannelResource::collection($request->user()->notificationChannels()->orderBy('id')->get());
    }

    public function store(StoreChannelRequest $request, ChannelTypeRegistry $registry, BeginChannelConnection $begin): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $type = $registry->find($data['channel_type']);

        if (! $user->canAddChannel($type->slug())) {
            return response()->json([
                'error' => 'channel_limit',
                'message' => 'Your plan\'s free channel is in use. Remove a channel, or upgrade, to add another.',
            ], 422);
        }

        try {
            $channel = $this->asUser($user, fn () => $begin($user, $type, $data, $data['label'] ?? null));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                (string) (array_key_first(Arr::except($data, ['channel_type', 'label'])) ?? 'channel_type') => $e->getMessage(),
            ]);
        }

        return (new ChannelResource($channel))->response()->setStatusCode(201);
    }

    public function verify(VerifyChannelRequest $request, int $id, VerifyChannelConfirmationCode $verify): ChannelResource|JsonResponse
    {
        $channel = $this->find($request, $id);

        $verified = $this->asUser($request->user(), fn () => $verify($channel, trim($request->validated('code'))));

        if (! $verified) {
            return response()->json([
                'error' => 'invalid_code',
                'message' => 'That code didn\'t match, or it expired. Request a new code if you need one.',
            ], 422);
        }

        return new ChannelResource($channel->fresh());
    }

    public function resend(Request $request, int $id, DispatchChannelConfirmationCode $dispatch): Response|JsonResponse
    {
        $channel = $this->find($request, $id);

        if ($channel->isVerified()) {
            return response()->json(['error' => 'already_verified', 'message' => 'This channel is already verified.'], 409);
        }

        if (! $dispatch($channel)) {
            throw new ChannelCodeNotSent;
        }

        return response()->noContent();
    }

    public function test(Request $request, int $id, SendTestNotification $send): Response|JsonResponse
    {
        $channel = $this->find($request, $id);

        if (! $channel->isVerified()) {
            return response()->json(['error' => 'not_verified', 'message' => 'Verify this channel before sending a test.'], 409);
        }

        if (! $send($channel)) {
            return response()->json([
                'error' => 'test_failed',
                'message' => 'Couldn\'t send the test notification. Check the channel is still reachable and try again.',
            ], 502);
        }

        return response()->noContent();
    }

    public function update(RenameChannelRequest $request, int $id, RenameChannel $rename): ChannelResource
    {
        return new ChannelResource($rename($request->user(), $this->find($request, $id), $request->validated('label')));
    }

    public function destroy(Request $request, int $id, DeleteChannel $delete): Response
    {
        $delete($request->user(), $this->find($request, $id));

        return response()->noContent();
    }

    /**
     * The user's own channel, or a 404; a phone's push channel is refused (409) before any write.
     */
    private function find(Request $request, int $id): NotificationChannel
    {
        $channel = $request->user()->notificationChannels()->find($id) ?? abort(404);

        if ($channel->channel_type === 'mobile_push') {
            throw new ChannelManagedByApp;
        }

        return $channel;
    }

    /**
     * The API has no session, so the activity log's causer must be pinned (see `.ai/rules/actions.md`).
     */
    private function asUser(User $user, Closure $call): mixed
    {
        return app(CauserResolver::class)->withCauser($user, $call);
    }
}
```

In `routes/api.php`, after the two GET routes from Task 3 add:

```php
        Route::post('/channels', [ChannelController::class, 'store'])->middleware('throttle:app-channel-send')->name('api.v1.channels.store');
        Route::post('/channels/{id}/verify', [ChannelController::class, 'verify'])->whereNumber('id')->name('api.v1.channels.verify');
        Route::post('/channels/{id}/resend', [ChannelController::class, 'resend'])->whereNumber('id')->middleware('throttle:app-channel-send')->name('api.v1.channels.resend');
        Route::post('/channels/{id}/test', [ChannelController::class, 'test'])->whereNumber('id')->middleware('throttle:app-channel-send')->name('api.v1.channels.test');
        Route::put('/channels/{id}', [ChannelController::class, 'update'])->whereNumber('id')->name('api.v1.channels.update');
        Route::delete('/channels/{id}', [ChannelController::class, 'destroy'])->whereNumber('id')->name('api.v1.channels.destroy');
```

In `app/Providers/AppServiceProvider.php`, next to the other `RateLimiter::for('app-…')` definitions:

```php
        // Create, resend and test each send a real message to a destination the subscriber typed:
        // ten an hour per subscriber, shared (the limiter name is the shared bucket).
        RateLimiter::for('app-channel-send', fn (Request $request) => Limit::perHour(10)
            ->by('app-channel-send:'.($request->user()?->id ?? $request->ip())));
```

- [ ] **Step 4: Run the tests**

Run `tests/Feature/ApiChannelWritesTest.php` and `tests/Feature/ApiChannelsTest.php`. Expected: PASS. If the limiter test cannot reset between tests, replace its `RateLimiter::clear(...)` line with `Cache::flush();` (add `use Illuminate\Support\Facades\Cache;` is already imported).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http app/Providers/AppServiceProvider.php routes/api.php tests/Feature/ApiChannelWritesTest.php
git commit -m "API: create, verify, resend, test, rename and delete channels

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Dashboard/API parity test and the recorded rules

**Files:**
- Create: `tests/Feature/ChannelApiParityTest.php`
- Modify: `.ai/rules/api.md`, `.ai/rules/actions.md`, `.ai/rules/support.md`

**Interfaces:**
- Consumes: `ConnectChannel` page, `goToConnectChannelStep()` (in `tests/Pest.php`), the API from Task 4.

- [ ] **Step 1: Write the test**

```php
<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Pages\ConnectChannel;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

use function Pest\Livewire\livewire;

/**
 * Runs one setup through the dashboard wizard and through the API, each as its own Pro subscriber
 * (so the Free slot never interferes), and returns what each stored, or null when it refused.
 *
 * @param  array<string, mixed>  $input
 * @return array{dashboard: ?array<string, mixed>, api: ?array<string, mixed>}
 */
function channelParityOutcome($test, string $slug, array $input): array
{
    $dashboardUser = User::factory()->create(['tier_override' => Tier::Pro]);
    $test->actingAs($dashboardUser);
    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => $slug]);
    goToConnectChannelStep($component, 0);
    $component->fillForm($input);
    goToConnectChannelStep($component, 1);

    $apiUser = User::factory()->create(['tier_override' => Tier::Pro]);
    $token = $apiUser->createToken('Pixel 9', ['app'])->plainTextToken;
    app('auth')->forgetGuards();
    $test->withToken($token)->postJson('/api/v1/channels', ['channel_type' => $slug, ...$input]);

    $stored = fn (User $user): ?array => ($channel = NotificationChannel::where('user_id', $user->id)->first())
        ? ['apprise_url' => $channel->apprise_url, 'config' => $channel->config, 'label' => $channel->label]
        : null;

    return ['dashboard' => $stored($dashboardUser), 'api' => $stored($apiUser)];
}

beforeEach(function () {
    chanFakePauser();
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    Mail::fake();
});

test('the dashboard wizard and the API accept the same setups and store the same destination', function (string $slug, array $valid) {
    foreach ($valid as $input) {
        $outcome = channelParityOutcome($this, $slug, $input);

        expect($outcome['dashboard'])->not->toBeNull(json_encode($input))
            ->and($outcome['api'])->toBe($outcome['dashboard']);
    }
})->with([
    'email' => ['email', [['to' => 'pat@example.com']]],
    'ntfy' => ['ntfy', [['topic' => 'wx-abc_123'], ['topic' => 'wx', 'server' => 'https://ntfy.example.com']]],
    'discord' => ['discord', [['webhook_url' => 'https://discord.com/api/webhooks/123456789/abcDEF-token_123']]],
    'slack' => ['slack', [['webhook_url' => 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX']]],
    'telegram' => ['telegram', [['bot_token' => '123456:ABC-def', 'chat_id' => '-1001234']]],
    'teams' => ['msteams', [['webhook_url' => 'https://prod-12.westus.logic.azure.com:443/workflows/0123456789abcdef0123456789abcdef/triggers/manual/paths/invoke?api-version=2016-06-01&sig=abc_DEF-123']]],
]);

test('the dashboard wizard and the API refuse the same setups', function (string $slug, array $invalid) {
    foreach ($invalid as $input) {
        $outcome = channelParityOutcome($this, $slug, $input);

        expect($outcome['dashboard'])->toBeNull(json_encode($input))
            ->and($outcome['api'])->toBeNull(json_encode($input));
    }
})->with([
    'email' => ['email', [['to' => 'not-an-email']]],
    'ntfy' => ['ntfy', [['topic' => 'has space'], ['topic' => 'wx', 'server' => 'notaurl']]],
    'discord' => ['discord', [['webhook_url' => 'https://example.com/hook'], ['webhook_url' => 'http://discord.com/api/webhooks/1/a']]],
    'slack' => ['slack', [['webhook_url' => 'https://hooks.slack.com/services/T0/B0']]],
    'telegram' => ['telegram', [['bot_token' => '123456:ABC-def']]],
    'teams' => ['msteams', [['webhook_url' => 'https://example.com/workflows/0123456789abcdef0123456789abcdef/triggers?sig=abc']]],
]);
```

- [ ] **Step 2: Run it.** Expected: PASS. A failure means a descriptor rule and its Filament field disagree: fix the descriptor (Task 1) so both accept and refuse the same input, not the test.

- [ ] **Step 3: Record the rules**

Append to `.ai/rules/api.md`:

```markdown
## Channel endpoints send real messages, are throttled per user and never return a destination
`POST /api/v1/channels`, `/channels/{id}/resend` and `/channels/{id}/test` each send a real message (a confirmation code or a test) to a destination the subscriber typed, so they share the `app-channel-send` limiter (10 per hour per user). A confirmation code is attempt-limited in `VerifyChannelConfirmationCode`: after 5 wrong tries the stored code is cleared and only a resend produces another (the dashboard's verify entry points get the same protection). Responses never carry `apprise_url`, `config`, a URL, a token or the code; `destination` is the type's masked `displaySummary()`. A `mobile_push` channel is listed but every write answers 409 `channel_managed_by_app` (it belongs to the app's own push registration). Only types whose `appSetupFields()` is not null can be created; the API validates from those descriptors and `ChannelApiParityTest` keeps them in step with the Filament forms.
```

Append to `.ai/rules/actions.md`:

```markdown
## A channel is created and sent its first code only through BeginChannelConnection
`App\Actions\Channels\BeginChannelConnection` stores the channel unverified, sends the confirmation code and removes a channel it just created when the code cannot be sent. The dashboard's `ConnectChannel` wizard and `POST /api/v1/channels` both call it; a change to what connecting a channel does belongs there, not in the page or the controller. It does not pin the activity causer (the wizard has a session, and impersonation attributes to the impersonator): the API controller wraps the call in `CauserResolver::withCauser`.
```

Append to `.ai/rules/support.md`:

```markdown
## A channel type the app can create declares appSetupFields() and needs a parity case
`ChannelType::appSetupFields(User)` returns neutral `ChannelSetupField` descriptors (key, label, input kind, required, help, default, validation rules) or null when the mobile app cannot create the type. Shared rule definitions (a URL pattern constant, `TeamsChannelType::workflowUrlRule()`) are referenced by both the Filament field and the descriptor. Adding a creatable type, or changing a field's rules, needs a sample in `ChannelSetupFieldsTest` and `ChannelApiParityTest`.
```

- [ ] **Step 4: Run the wider backend regression:** `tests/Feature/ChannelApiParityTest.php`, `ChannelConnectionTest.php`, `ChannelTypesTest.php`, `WebPushChannelTest.php`, `RevealChannelUrlTest.php`, `NotificationChannelResourceTest.php`, `ApiChannelsTest.php`, `ApiChannelWritesTest.php`. Expected: all PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/Feature/ChannelApiParityTest.php .ai/rules
git commit -m "Channels: dashboard/API parity test and recorded rules

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: App API client, typed errors and the cache

**Files (all under `mobile/`):**
- Create: `app/Exceptions/Api/{ChannelLimitReached,CodeNotSent,InvalidCode,TestFailed}.php`
- Modify: `app/Services/WxNotifyApi.php`, `app/Support/LocalCache.php`
- Test: `tests/Feature/WxNotifyApiChannelsTest.php`, `tests/Feature/LocalCacheChannelsTest.php`

**Interfaces:**
- Produces: `WxNotifyApi::channelTypes(): array`, `channels(): array`, `createChannel(array $payload): array`, `verifyChannel(int $id, string $code): array`, `resendChannelCode(int $id): void`, `testChannel(int $id): void`, `renameChannel(int $id, string $label): array`, `deleteChannel(int $id): void`; exceptions `ChannelLimitReached` (message = server text), `CodeNotSent`, `InvalidCode` (message = server text), `TestFailed`; `Conflict` carries `reason` `channel_in_use` | `already_verified` | `not_verified` | `channel_managed_by_app`. `LocalCache::rememberChannels(array)`, `channels(): ?array`, wiped by `wipe()`.

- [ ] **Step 1: Write the failing tests**

`mobile/tests/Feature/WxNotifyApiChannelsTest.php`:

```php
<?php

use App\Exceptions\Api\ChannelLimitReached;
use App\Exceptions\Api\CodeNotSent;
use App\Exceptions\Api\Conflict;
use App\Exceptions\Api\InvalidCode;
use App\Exceptions\Api\Retryable;
use App\Exceptions\Api\TestFailed;
use App\Exceptions\Api\ValidationFailed;
use App\Services\WxNotifyApi;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    (new TokenStore)->put('9|secret');
    $this->api = app(WxNotifyApi::class);
});

test('the channel types and the channels are unwrapped from their data key', function () {
    Http::fake([
        'wx.test/api/v1/channel-types' => Http::response(['data' => [['slug' => 'email']]]),
        'wx.test/api/v1/channels' => Http::response(['data' => [['id' => 4]]]),
    ]);

    expect($this->api->channelTypes())->toBe([['slug' => 'email']])
        ->and($this->api->channels())->toBe([['id' => 4]]);
});

test('each write goes to its endpoint with its body', function () {
    Http::fake([
        'wx.test/api/v1/channels' => Http::response(['data' => ['id' => 9]], 201),
        'wx.test/api/v1/channels/9/verify' => Http::response(['data' => ['id' => 9, 'verified' => true]]),
        'wx.test/api/v1/channels/9/resend' => Http::response(status: 204),
        'wx.test/api/v1/channels/9/test' => Http::response(status: 204),
        'wx.test/api/v1/channels/9' => fn (Request $request) => $request->method() === 'PUT'
            ? Http::response(['data' => ['id' => 9, 'label' => 'New']])
            : Http::response(status: 204),
    ]);

    expect($this->api->createChannel(['channel_type' => 'ntfy', 'topic' => 'wx']))->toBe(['id' => 9])
        ->and($this->api->verifyChannel(9, '123456'))->toBe(['id' => 9, 'verified' => true])
        ->and($this->api->renameChannel(9, 'New'))->toBe(['id' => 9, 'label' => 'New']);

    $this->api->resendChannelCode(9);
    $this->api->testChannel(9);
    $this->api->deleteChannel(9);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://wx.test/api/v1/channels' && $r->data() === ['channel_type' => 'ntfy', 'topic' => 'wx']);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/channels/9/verify') && $r->data() === ['code' => '123456']);
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->data() === ['label' => 'New']);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/channels/9'));
});

test('the server\'s refusals become typed exceptions', function (int $status, array $body, string $exception) {
    Http::fake(['wx.test/*' => Http::response($body, $status)]);

    expect(fn () => $this->api->createChannel(['channel_type' => 'ntfy']))->toThrow($exception);
})->with([
    'limit' => [422, ['error' => 'channel_limit', 'message' => 'Full.'], ChannelLimitReached::class],
    'wrong code' => [422, ['error' => 'invalid_code', 'message' => 'Nope.'], InvalidCode::class],
    'fields' => [422, ['message' => 'Invalid.', 'errors' => ['topic' => ['Bad topic.']]], ValidationFailed::class],
    'code not sent' => [502, ['error' => 'code_not_sent', 'message' => 'x'], CodeNotSent::class],
    'test failed' => [502, ['error' => 'test_failed', 'message' => 'x'], TestFailed::class],
    'in use' => [409, ['error' => 'channel_in_use', 'message' => 'x', 'alerts' => ['A']], Conflict::class],
    'a plain gateway error' => [502, ['message' => 'Bad gateway'], Retryable::class],
]);

test('the exceptions carry the server\'s text and codes', function () {
    Http::fake(['wx.test/*' => Http::response(['error' => 'invalid_code', 'message' => 'Nope.'], 422)]);

    try {
        $this->api->verifyChannel(9, '000000');
    } catch (InvalidCode $e) {
        expect($e->getMessage())->toBe('Nope.');
    }

    Http::fake(['wx.test/*' => Http::response(['error' => 'channel_in_use', 'message' => 'In use.', 'alerts' => ['Storm']], 409)]);

    try {
        $this->api->deleteChannel(9);
    } catch (Conflict $e) {
        expect($e->reason)->toBe('channel_in_use')->and($e->alerts)->toBe(['Storm']);
    }
});
```

`mobile/tests/Feature/LocalCacheChannelsTest.php`:

```php
<?php

use App\Support\LocalCache;

test('the channel list is remembered and wiped on sign-out', function () {
    $cache = new LocalCache;

    expect($cache->channels())->toBeNull();

    $cache->rememberChannels([['id' => 1, 'label' => 'Email']]);

    expect($cache->channels())->toBe([['id' => 1, 'label' => 'Email']]);

    $cache->wipe();

    expect($cache->channels())->toBeNull();
});
```

- [ ] **Step 2: Run them to see them fail** (`cd mobile && vendor/bin/pest tests/Feature/WxNotifyApiChannelsTest.php tests/Feature/LocalCacheChannelsTest.php`).

- [ ] **Step 3: Implement**

Create the four exceptions in `mobile/app/Exceptions/Api/`, each like this (`ChannelLimitReached` shown; `CodeNotSent`, `InvalidCode`, `TestFailed` are identical apart from the name and the docblock):

```php
<?php

namespace App\Exceptions\Api;

/**
 * The subscriber's plan has no room for another channel: the server answered 422 channel_limit.
 * `getMessage()` is the server's text.
 */
final class ChannelLimitReached extends ApiException {}
```

Docblocks: `CodeNotSent` — "The server could not deliver the confirmation code (502 code_not_sent)."; `InvalidCode` — "The confirmation code was wrong or expired (422 invalid_code). `getMessage()` is the server's text."; `TestFailed` — "The test notification could not be delivered (502 test_failed)."

In `WxNotifyApi.php` add imports for the four exceptions; add these methods before `searchPlaces()`:

```php
    /**
     * The channel types the app can create, each with its setup fields.
     *
     * @return array<int, array<string, mixed>>
     */
    public function channelTypes(): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/channel-types')));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function channels(): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/channels')));
    }

    /**
     * Creates a channel and has the server send its confirmation code.
     *
     * @param  array<string, mixed>  $payload  channel_type, optional label, and the type's fields
     * @return array<string, mixed>
     *
     * @throws ChannelLimitReached
     * @throws CodeNotSent
     * @throws ValidationFailed
     */
    public function createChannel(array $payload): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->post('/api/v1/channels', $payload)));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidCode
     */
    public function verifyChannel(int $id, string $code): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->post("/api/v1/channels/{$id}/verify", ['code' => $code])));
    }

    /**
     * @throws CodeNotSent
     */
    public function resendChannelCode(int $id): void
    {
        $this->send(fn (PendingRequest $request) => $request->post("/api/v1/channels/{$id}/resend"));
    }

    /**
     * @throws TestFailed
     */
    public function testChannel(int $id): void
    {
        $this->send(fn (PendingRequest $request) => $request->post("/api/v1/channels/{$id}/test"));
    }

    /**
     * @return array<string, mixed>
     */
    public function renameChannel(int $id, string $label): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->put("/api/v1/channels/{$id}", ['label' => $label])));
    }

    /**
     * @throws Conflict when the channel is some alert's only one
     */
    public function deleteChannel(int $id): void
    {
        $this->send(fn (PendingRequest $request) => $request->delete("/api/v1/channels/{$id}"));
    }
```

In `send()`'s `match (true)`, insert the two 502 arms before the `429 / serverError` arm and the two 422 arms after the `location_limit` arm:

```php
            $response->status() === 502 && $response->json('error') === 'code_not_sent' => throw new CodeNotSent,
            $response->status() === 502 && $response->json('error') === 'test_failed' => throw new TestFailed,
            $response->status() === 429, $response->serverError() => throw new Retryable,
            $response->status() === 422 && $response->json('error') === 'location_limit' => throw new LocationLimitReached,
            $response->status() === 422 && $response->json('error') === 'channel_limit' => throw new ChannelLimitReached((string) $response->json('message', '')),
            $response->status() === 422 && $response->json('error') === 'invalid_code' => throw new InvalidCode((string) $response->json('message', '')),
```

In `LocalCache.php`: add `private const CHANNELS = 'wxnotify.channels';`, update the class docblock to mention "notification channels", add after `locationsMeta()`:

```php
    /**
     * @param  array<int, array<string, mixed>>  $channels
     */
    public function rememberChannels(array $channels): void
    {
        Cache::forever(self::CHANNELS, $channels);
    }

    /**
     * @return array<int, array<string, mixed>>|null null until the list was fetched once
     */
    public function channels(): ?array
    {
        return Cache::get(self::CHANNELS);
    }
```

and add `Cache::forget(self::CHANNELS);` to `wipe()`.

- [ ] **Step 4: Run the two new files and `tests/Feature/WxNotifyApiTest.php`, `WxNotifyApiManagementTest.php`, `LocalCacheTest.php`, `LocalCacheManagementTest.php`.** Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
cd mobile && vendor/bin/pint --dirty --format agent && cd ..
git add mobile/app mobile/tests
git commit -m "App: channel API calls, typed errors and the cached channel list

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Settings row, the Channels list screen and the rule-editor text

**Files (under `mobile/`):**
- Create: `app/NativeComponents/ChannelsScreen.php`, `resources/views/native/channels-screen.blade.php`
- Modify: `app/NativeComponents/SettingsScreen.php`, `resources/views/native/settings-screen.blade.php`, `routes/mobile.php`, `resources/views/native/rule-editor.blade.php`
- Test: `tests/Feature/ChannelsScreenTest.php`; modify `tests/Feature/SettingsScreenTest.php`, `tests/Feature/RuleEditorTest.php`

**Interfaces:**
- Consumes: `WxNotifyApi::channels()`, `LocalCache::channels()/rememberChannels()`.
- Produces: routes `/channels` (list), `ChannelsScreen::create()` → navigates `/channels/new`, `ChannelsScreen::open(int $id)` → navigates `/channels/{id}`; `SettingsScreen::openChannels()` → `/channels`.

- [ ] **Step 1: Write the failing tests**

`mobile/tests/Feature/ChannelsScreenTest.php`:

```php
<?php

use App\NativeComponents\ChannelsScreen;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function listedChannel(array $overrides = []): array
{
    return [
        'id' => 1, 'label' => 'Team Discord', 'channel_type' => 'discord', 'type_label' => 'Discord', 'destination' => '••••1234',
        'destination_label' => 'Discord webhook', 'verified' => true, 'managed_by_app' => false, 'created_at' => '2026-10-01T12:00:00+00:00',
        ...$overrides,
    ];
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('the list shows each channel with its type, masked destination and whether it still needs verifying', function () {
    Http::fake(['wx.test/api/v1/channels' => Http::response(['data' => [
        listedChannel(),
        listedChannel(['id' => 2, 'label' => 'Work email', 'channel_type' => 'email', 'type_label' => 'Email', 'destination' => 'pat@example.com', 'verified' => false]),
        listedChannel(['id' => 3, 'label' => 'Pixel 9', 'channel_type' => 'mobile_push', 'type_label' => 'Mobile Push', 'destination' => null, 'managed_by_app' => true]),
    ]])]);

    Native::test(ChannelsScreen::class)
        ->assertNavTitle('Channels')
        ->assertHasTab('Alerts')
        ->assertTabActive('Settings')
        ->assertSee('Team Discord')
        ->assertSee('Discord · ••••1234')
        ->assertSee('Email · pat@example.com · Not verified')
        ->assertSee('Mobile Push · Managed in Account')
        ->assertElement('button', fn (array $b) => $b['props']['label'] === 'Add channel');
});

test('tapping a channel opens it, Add channel opens the new-channel screen, and a phone\'s own channel does not open', function () {
    Http::fake(['wx.test/api/v1/channels' => Http::response(['data' => [listedChannel(), listedChannel(['id' => 3, 'label' => 'Pixel 9', 'managed_by_app' => true])]])]);

    Native::test(ChannelsScreen::class)
        ->tap('channel-1')
        ->assertNavigatedTo('/channels/1');

    Native::test(ChannelsScreen::class)->tap('add-channel')->assertNavigatedTo('/channels/new');

    Native::test(ChannelsScreen::class)->assertMissingElement('pressable', fn (array $p) => ($p['ref'] ?? null) === 'channel-3');
});

test('with no channels it says so', function () {
    Http::fake(['wx.test/api/v1/channels' => Http::response(['data' => []])]);

    Native::test(ChannelsScreen::class)->assertSee('No channels yet');
});

test('offline it shows the last list with a note, and with nothing remembered it does not claim there are none', function () {
    (new LocalCache)->rememberChannels([listedChannel()]);
    $this->network->connected = false;

    Native::test(ChannelsScreen::class)
        ->assertSee('You\'re offline. Showing your channels as last loaded.')
        ->assertSee('Team Discord');

    (new LocalCache)->wipe();

    Native::test(ChannelsScreen::class)->assertSee('You\'re offline.')->assertDontSee('No channels yet');
});

test('a failing server shows a message with Try again, and the list is remembered when it loads', function () {
    Http::fake(['wx.test/api/v1/channels' => Http::response(['message' => 'boom'], 500)]);

    Native::test(ChannelsScreen::class)->assertSee('WxNotify could not load your channels. Try again in a moment.')->assertSee('Try again');

    Http::fake(['wx.test/api/v1/channels' => Http::response(['data' => [listedChannel()]])]);
    Native::test(ChannelsScreen::class);

    expect((new LocalCache)->channels())->toHaveCount(1);
});

test('a signed-out visitor is sent to Sign in, and a rejected token signs out', function () {
    (new TokenStore)->clear();
    Native::test(ChannelsScreen::class)->assertReplacedWith('/sign-in');

    (new TokenStore)->put('9|secret');
    Http::fake(['wx.test/api/v1/channels' => Http::response([], 401)]);
    Native::test(ChannelsScreen::class)->assertReplacedWith('/sign-in');
});
```

In `mobile/tests/Feature/SettingsScreenTest.php` extend the first test with `->assertSee('Channels')` and add `['openChannels', '/channels']` to the dataset.

In `mobile/tests/Feature/RuleEditorTest.php` line 264, change `->assertSee('add a channel on wxnotify.com')` to `->assertSee('add one under Settings → Channels')`.

- [ ] **Step 2: Run them to see them fail.**

- [ ] **Step 3: Implement**

`mobile/app/NativeComponents/ChannelsScreen.php`:

```php
<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Maintenance;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Unauthenticated;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use Native\Mobile\Edge\NativeComponent;

/**
 * The subscriber's notification channels: where alerts are sent, and whether each is verified.
 * Paints from the cache first, refreshes, and keeps the last copy with a note when offline.
 */
class ChannelsScreen extends NativeComponent
{
    use RequiresSignIn;

    /** @var array<int, array<string, mixed>> */
    public array $channels = [];

    public bool $offline = false;

    /** Whether the list on screen is known (remembered or just loaded): only then can it be "empty". */
    public bool $loaded = false;

    public ?string $error = null;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $cached = app(LocalCache::class)->channels();
        $this->channels = $cached ?? [];
        $this->loaded = $cached !== null;

        $this->refresh();
    }

    /**
     * Back from the new-channel or channel screen (added, renamed, verified, deleted): the list may have changed.
     */
    public function onResume(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        try {
            $this->channels = app(WxNotifyApi::class)->channels();
            app(LocalCache::class)->rememberChannels($this->channels);
            $this->offline = false;
            $this->error = null;
            $this->loaded = true;
        } catch (Unauthenticated) {
            app(SignOut::class)->clearLocal();
            $this->replace('/sign-in');
        } catch (Offline) {
            $this->offline = true;
            $this->error = null;
        } catch (Maintenance $e) {
            $this->offline = false;
            $this->error = $e->getMessage();
        } catch (ApiException) {
            $this->offline = false;
            $this->error = 'WxNotify could not load your channels. Try again in a moment.';
        }
    }

    public function create(): void
    {
        $this->navigate('/channels/new');
    }

    public function open(int $id): void
    {
        $this->navigate("/channels/{$id}");
    }

    /**
     * The type, then where it sends (masked) or who manages it, then whether it still needs verifying.
     *
     * @param  array<string, mixed>  $channel
     */
    public function subtitle(array $channel): string
    {
        $parts = [(string) $channel['type_label']];

        if ($channel['managed_by_app']) {
            $parts[] = 'Managed in Account';
        } else {
            if (filled($channel['destination'] ?? null)) {
                $parts[] = (string) $channel['destination'];
            }

            if (! $channel['verified']) {
                $parts[] = 'Not verified';
            }
        }

        return implode(' · ', $parts);
    }
}
```

`mobile/resources/views/native/channels-screen.blade.php`:

```blade
<native:top-bar title="Channels" show-navigation-icon="true"></native:top-bar>

@include('native.partials.tabs', ['active' => 'settings'])

<native:column class="w-full h-full bg-theme-background">
    @if ($offline)
        <native:row class="w-full p-3 bg-theme-surface">
            <native:text class="text-sm text-theme-on-surface-variant">{{ $loaded && count($channels) > 0 ? "You're offline. Showing your channels as last loaded." : "You're offline." }}</native:text>
        </native:row>
    @endif

    @if ($error)
        <native:column class="w-full p-3 gap-2 bg-theme-surface">
            <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
            <native:button label="Try again" variant="secondary" @tap="refresh" ref="retry-channels" class="w-full" />
        </native:column>
    @endif

    <native:scroll-view class="w-full flex-1 bg-theme-background">
        <native:column class="w-full p-4 gap-2">
            <native:button label="Add channel" @tap="create" ref="add-channel" class="w-full" />

            @forelse ($channels as $channel)
                @if ($channel['managed_by_app'])
                    <native:column class="w-full gap-1 p-2">
                        <native:text class="text-base font-semibold text-theme-on-background">{{ $channel['label'] }}</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant">{{ $this->subtitle($channel) }}</native:text>
                    </native:column>
                @else
                    {{-- Un-prefixed on purpose: native:validate does not know <pressable> (a bare tag compiles the same). The handler argument goes through {{ }} so the validator skips it. --}}
                    <pressable @tap="open({{ $channel['id'] }})" ref="channel-{{ $channel['id'] }}" class="w-full">
                        <native:column class="w-full gap-1 p-2">
                            <native:text class="text-base font-semibold text-theme-on-background">{{ $channel['label'] }}</native:text>
                            <native:text class="text-sm text-theme-on-surface-variant">{{ $this->subtitle($channel) }}</native:text>
                        </native:column>
                    </pressable>
                @endif
                <native:divider></native:divider>
            @empty
                @if ($loaded && ! $error)
                    <native:column class="w-full p-6 items-center gap-2">
                        <native:text class="text-lg font-semibold text-theme-on-background">No channels yet</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant text-center">Channels are where your alerts are sent. Tap Add channel to connect one.</native:text>
                    </native:column>
                @endif
            @endforelse
        </native:column>
    </native:scroll-view>
</native:column>
```

`SettingsScreen.php`: add

```php
    public function openChannels(): void
    {
        $this->navigate('/channels');
    }
```

`settings-screen.blade.php`: after the Locations `list-item` add:

```blade
    <list-item headline="Channels" supporting="Where your alerts are sent" @tap="openChannels"></list-item>
```

`routes/mobile.php`: add `use App\NativeComponents\ChannelsScreen;` (alphabetical) and, after the `/locations/{id}` route:

```php
Route::native('/channels', ChannelsScreen::class);
```

(the `/channels/new` and `/channels/{id}` routes are added in Tasks 8 and 9, `new` first).

`rule-editor.blade.php` line 177: replace the sentence with `You have no channels yet. Turn on push notifications in Account, or add one under Settings → Channels.`

- [ ] **Step 4: Run** `vendor/bin/pest tests/Feature/ChannelsScreenTest.php tests/Feature/SettingsScreenTest.php tests/Feature/RuleEditorTest.php`, then `php artisan view:clear && php artisan native:validate`. Expected: PASS and "All components passed validation".

- [ ] **Step 5: Format and commit**

```bash
cd mobile && vendor/bin/pint --dirty --format agent && cd ..
git add mobile/app mobile/resources mobile/routes mobile/tests
git commit -m "App: Channels list under Settings

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 8: The new-channel screen

**Files (under `mobile/`):**
- Create: `app/NativeComponents/ChannelCreator.php`, `resources/views/native/channel-creator.blade.php`
- Modify: `routes/mobile.php`
- Test: `tests/Feature/ChannelCreatorTest.php`

**Interfaces:**
- Consumes: `WxNotifyApi::channelTypes()`, `createChannel()`; exceptions from Task 6.
- Produces: route `/channels/new`; on success `replace("/channels/{id}")`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\NativeComponents\ChannelCreator;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function creatorTypes(array $discordOverrides = []): array
{
    return [
        ['slug' => 'email', 'label' => 'Email', 'can_add' => true, 'unavailable_reason' => null, 'fields' => [
            ['key' => 'to', 'label' => 'Email address', 'input' => 'email', 'required' => true, 'help' => null, 'placeholder' => null, 'default' => 'pat@example.com'],
        ]],
        ['slug' => 'discord', 'label' => 'Discord', 'can_add' => true, 'unavailable_reason' => null, 'fields' => [
            ['key' => 'webhook_url', 'label' => 'Webhook URL', 'input' => 'secret', 'required' => true, 'help' => 'From your Discord channel settings.', 'placeholder' => null, 'default' => null],
        ], ...$discordOverrides],
        ['slug' => 'ntfy', 'label' => 'ntfy', 'can_add' => true, 'unavailable_reason' => null, 'fields' => [
            ['key' => 'topic', 'label' => 'Topic', 'input' => 'text', 'required' => true, 'help' => null, 'placeholder' => null, 'default' => null],
            ['key' => 'server', 'label' => 'Server (optional)', 'input' => 'url', 'required' => false, 'help' => null, 'placeholder' => null, 'default' => null],
        ]],
    ];
}

function creatorFakes(array $extra = [], ?array $types = null): void
{
    Http::fake([
        ...$extra,
        'wx.test/api/v1/channel-types' => Http::response(['data' => $types ?? creatorTypes()]),
    ]);
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('it lists the types the server offers', function () {
    creatorFakes();

    Native::test(ChannelCreator::class)
        ->assertNavTitle('Add channel')
        ->assertSee('Email')
        ->assertSee('Discord')
        ->assertSee('ntfy');
});

test('a type the plan cannot add now is shown disabled and tapping it says why', function () {
    creatorFakes(types: [
        ...array_slice(creatorTypes(), 0, 1),
        ['slug' => 'discord', 'label' => 'Discord', 'can_add' => false, 'unavailable_reason' => 'channel_limit', 'fields' => []],
    ]);

    Native::test(ChannelCreator::class)
        ->assertSee('Not available on your plan right now')
        ->tap('type-discord')
        ->assertSet('selected', null)
        ->assertSee('Your plan\'s free channel is in use');
});

test('picking a type shows its fields from the descriptors, with the default filled and a secret masked', function () {
    creatorFakes();

    Native::test(ChannelCreator::class)
        ->tap('type-email')
        ->assertSet('values.to', 'pat@example.com')
        ->assertSee('Email address')
        ->assertSee('Send code');

    Native::test(ChannelCreator::class)
        ->tap('type-discord')
        ->assertSee('Webhook URL')
        ->assertSee('From your Discord channel settings.')
        ->assertElement('outlined_text_input', fn (array $input) => ($input['ref'] ?? null) === 'field-webhook_url' && ($input['props']['secure'] ?? false) === true);
});

test('a required field left empty is refused locally and nothing is sent', function () {
    creatorFakes();

    Native::test(ChannelCreator::class)
        ->tap('type-discord')
        ->tap('send-code')
        ->assertSee('Webhook URL is required.')
        ->assertNoNavigation();

    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

test('sending posts the type and the trimmed fields without empty optional ones, clears them and opens the channel to confirm', function () {
    creatorFakes(['wx.test/api/v1/channels' => Http::response(['data' => ['id' => 9, 'label' => 'ntfy']], 201)]);

    $screen = Native::test(ChannelCreator::class)
        ->tap('type-ntfy')
        ->input('field-topic', ' wx-topic ')
        ->tap('send-code')
        ->assertReplacedWith('/channels/9');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['channel_type' => 'ntfy', 'topic' => 'wx-topic']);
    $screen->assertSet('values', []);
});

test('a name given is sent as the label', function () {
    creatorFakes(['wx.test/api/v1/channels' => Http::response(['data' => ['id' => 9]], 201)]);

    Native::test(ChannelCreator::class)
        ->tap('type-ntfy')
        ->input('field-topic', 'wx')
        ->input('field-label', 'Phone alerts')
        ->tap('send-code');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['channel_type' => 'ntfy', 'topic' => 'wx', 'label' => 'Phone alerts']);
});

test('the server\'s per-field messages show under the field, and the typed value stays', function () {
    creatorFakes(['wx.test/api/v1/channels' => Http::response(['message' => 'Invalid.', 'errors' => ['webhook_url' => ['The webhook url format is invalid.']]], 422)]);

    Native::test(ChannelCreator::class)
        ->tap('type-discord')
        ->input('field-webhook_url', 'https://example.com/hook')
        ->tap('send-code')
        ->assertSee('The webhook url format is invalid.')
        ->assertSet('values.webhook_url', 'https://example.com/hook')
        ->assertNoNavigation();
});

test('the limit, an undeliverable code, offline and maintenance each say so beside the button and keep the form', function (callable $fake, string $text) {
    $fake();

    Native::test(ChannelCreator::class)
        ->tap('type-ntfy')
        ->input('field-topic', 'wx')
        ->tap('send-code')
        ->assertSee($text)
        ->assertSet('values.topic', 'wx')
        ->assertNoNavigation();
})->with([
    'limit' => [fn () => creatorFakes(['wx.test/api/v1/channels' => Http::response(['error' => 'channel_limit', 'message' => 'Your plan\'s free channel is in use. Remove a channel, or upgrade, to add another.'], 422)]), 'free channel is in use'],
    'code not sent' => [fn () => creatorFakes(['wx.test/api/v1/channels' => Http::response(['error' => 'code_not_sent', 'message' => 'x'], 502)]), 'couldn\'t send the confirmation code'],
    'maintenance' => [fn () => creatorFakes(['wx.test/api/v1/channels' => Http::response('Back soon.', 503, ['Content-Type' => 'text/plain'])]), 'Back soon.'],
    'a server error' => [fn () => creatorFakes(['wx.test/api/v1/channels' => Http::response([], 500)]), 'Couldn\'t add the channel'],
]);

test('offline it says to reconnect, both when opening and when sending', function () {
    $this->network->connected = false;

    Native::test(ChannelCreator::class)->assertSee('Connect to the internet to add a channel.')->assertSee('Try again');

    $this->network->connected = true;
    creatorFakes();
    $screen = Native::test(ChannelCreator::class)->tap('type-ntfy')->input('field-topic', 'wx');
    $this->network->connected = false;

    $screen->tap('send-code')->assertSee('You\'re offline. Reconnect to send the code.')->assertSet('values.topic', 'wx');
});

test('another type can be chosen again without carrying over the old values', function () {
    creatorFakes();

    Native::test(ChannelCreator::class)
        ->tap('type-discord')
        ->input('field-webhook_url', 'https://discord.com/api/webhooks/1/a')
        ->tap('Choose another type')
        ->assertSet('selected', null)
        ->assertSet('values', [])
        ->tap('type-email')
        ->assertSet('values', ['to' => 'pat@example.com']);
});

test('a signed-out visitor is sent to Sign in', function () {
    (new TokenStore)->clear();

    Native::test(ChannelCreator::class)->assertReplacedWith('/sign-in');
});
```

- [ ] **Step 2: Run it to see it fail.**

- [ ] **Step 3: Implement**

`mobile/app/NativeComponents/ChannelCreator.php`:

```php
<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\ChannelLimitReached;
use App\Exceptions\Api\CodeNotSent;
use App\Exceptions\Api\Maintenance;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Unauthenticated;
use App\Exceptions\Api\ValidationFailed;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\Concerns\RequiresSignIn;
use Native\Mobile\Edge\NativeComponent;

/**
 * Adds a channel: pick one of the types the server offers, fill in its fields (described by the
 * server, so a type's setup is never hard-coded here) and have the server send a confirmation
 * code. On success the screen is replaced by the channel's own screen, where the code is entered.
 * Typed values (a webhook URL or bot token is a password) live only in this component and are
 * cleared once the channel exists.
 */
class ChannelCreator extends NativeComponent
{
    use RequiresSignIn;

    /** @var array<int, array<string, mixed>> */
    public array $types = [];

    public bool $ready = false;

    /** Why the types could not be loaded. */
    public ?string $banner = null;

    public bool $canRetry = false;

    /** Index into $types of the chosen type; null while choosing. */
    public ?int $selected = null;

    /** @var array<string, string> field key => value */
    public array $values = [];

    public string $label = '';

    /** @var array<string, string> */
    public array $errors = [];

    /** The message beside the button (or under the list while choosing). */
    public ?string $feedback = null;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $this->load();
    }

    public function load(): void
    {
        $this->banner = null;
        $this->canRetry = false;

        try {
            $this->types = array_values(app(WxNotifyApi::class)->channelTypes());
            $this->ready = true;
        } catch (Unauthenticated) {
            $this->signOutLocally();
        } catch (Offline) {
            $this->banner = 'Connect to the internet to add a channel.';
            $this->canRetry = true;
        } catch (Maintenance $e) {
            $this->banner = $e->getMessage();
            $this->canRetry = true;
        } catch (ApiException) {
            $this->banner = 'WxNotify could not load the channel types. Try again in a moment.';
            $this->canRetry = true;
        }
    }

    public function pickType(int $index): void
    {
        $this->feedback = null;
        $type = $this->types[$index] ?? null;

        if ($type === null) {
            return;
        }

        if (! $type['can_add']) {
            $this->feedback = 'Your plan\'s free channel is in use. Remove a channel, or upgrade, to add another.';

            return;
        }

        $this->selected = $index;
        $this->values = collect($type['fields'])->mapWithKeys(fn (array $field): array => [$field['key'] => (string) ($field['default'] ?? '')])->all();
        $this->label = '';
        $this->errors = [];
    }

    public function chooseAnother(): void
    {
        $this->selected = null;
        $this->values = [];
        $this->label = '';
        $this->errors = [];
        $this->feedback = null;
    }

    public function send(): void
    {
        $type = $this->selected === null ? null : ($this->types[$this->selected] ?? null);

        if ($type === null) {
            return;
        }

        $this->feedback = null;
        $this->errors = $this->validateValues($type);

        if ($this->errors !== []) {
            return;
        }

        try {
            $channel = app(WxNotifyApi::class)->createChannel($this->payload($type));
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (ChannelLimitReached $e) {
            $this->feedback = $e->getMessage() !== '' ? $e->getMessage() : 'Your plan\'s free channel is in use. Remove a channel, or upgrade, to add another.';

            return;
        } catch (CodeNotSent) {
            $this->feedback = 'We couldn\'t send the confirmation code. Please try again in a moment.';

            return;
        } catch (ValidationFailed $e) {
            $this->errors = $this->mapServerErrors($type, $e->errors);

            return;
        } catch (Maintenance $e) {
            $this->feedback = $e->getMessage();

            return;
        } catch (Offline) {
            $this->feedback = 'You\'re offline. Reconnect to send the code.';

            return;
        } catch (ApiException) {
            $this->feedback = 'Couldn\'t add the channel. Please try again.';

            return;
        }

        $this->values = [];
        $this->label = '';
        $this->replace("/channels/{$channel['id']}");
    }

    /**
     * The on-screen keyboard that suits a field.
     *
     * @param  array<string, mixed>  $field
     */
    public function keyboardFor(array $field): string
    {
        return match ($field['input']) {
            'email' => 'email',
            'url' => 'url',
            default => 'text',
        };
    }

    /**
     * @param  array<string, mixed>  $type
     * @return array<string, string>
     */
    private function validateValues(array $type): array
    {
        $errors = [];

        foreach ($type['fields'] as $field) {
            if ($field['required'] && trim($this->values[$field['key']] ?? '') === '') {
                $errors[$field['key']] = "{$field['label']} is required.";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $type
     * @return array<string, mixed>
     */
    private function payload(array $type): array
    {
        $payload = ['channel_type' => $type['slug']];

        foreach ($type['fields'] as $field) {
            $value = trim($this->values[$field['key']] ?? '');

            if ($value !== '') {
                $payload[$field['key']] = $value;
            }
        }

        if (trim($this->label) !== '') {
            $payload['label'] = trim($this->label);
        }

        return $payload;
    }

    /**
     * A message for a field this screen shows goes under it; any other goes beside the button.
     *
     * @param  array<string, mixed>  $type
     * @param  array<string, string>  $serverErrors
     * @return array<string, string>
     */
    private function mapServerErrors(array $type, array $serverErrors): array
    {
        $known = [...array_column($type['fields'], 'key'), 'label'];
        $errors = [];

        foreach ($serverErrors as $field => $message) {
            if (in_array($field, $known, true)) {
                $errors[$field] = $message;
            } else {
                $this->feedback ??= $message;
            }
        }

        return $errors;
    }

    private function signOutLocally(): void
    {
        app(SignOut::class)->clearLocal();
        $this->replace('/sign-in');
    }
}
```

`mobile/resources/views/native/channel-creator.blade.php`:

```blade
{{-- Un-prefixed on purpose: native:validate does not know <pressable> or <outlined-text-input> (a bare tag compiles the same). Handler arguments go through {{ }} so the validator skips them. --}}

<native:top-bar title="Add channel" show-navigation-icon="true"></native:top-bar>

<native:scroll-view class="w-full h-full bg-theme-background">
    <native:column class="w-full p-4 gap-4">
        @if ($banner)
            <native:text class="text-sm text-theme-destructive">{{ $banner }}</native:text>
        @endif

        @if ($canRetry)
            <native:button label="Try again" variant="secondary" @tap="load" ref="retry-types" class="w-full" />
        @endif

        @if ($ready && $selected === null)
            <native:text class="text-base text-theme-on-surface-variant">Where should your alerts be sent?</native:text>

            @foreach ($types as $index => $type)
                <pressable @tap="pickType({{ $index }})" ref="type-{{ $type['slug'] }}" class="w-full">
                    <native:column class="w-full gap-1 p-2">
                        <native:text class="text-base font-semibold {{ $type['can_add'] ? 'text-theme-on-background' : 'text-theme-on-surface-variant' }}">{{ $type['label'] }}</native:text>
                        @unless ($type['can_add'])
                            <native:text class="text-sm text-theme-on-surface-variant">Not available on your plan right now</native:text>
                        @endunless
                    </native:column>
                </pressable>
                <native:divider></native:divider>
            @endforeach

            @if ($feedback)
                <native:text class="text-sm text-theme-destructive">{{ $feedback }}</native:text>
            @endif
        @endif

        @if ($ready && $selected !== null)
            @php($type = $types[$selected])

            <native:text class="text-lg font-bold text-theme-on-background">{{ $type['label'] }}</native:text>

            @foreach ($type['fields'] as $field)
                @if ($field['input'] === 'secret')
                    <outlined-text-input label="{{ $field['label'] }}" native:model="values.{{ $field['key'] }}" secure revealable ref="field-{{ $field['key'] }}" />
                @else
                    <outlined-text-input label="{{ $field['label'] }}" native:model="values.{{ $field['key'] }}" keyboard="{{ $this->keyboardFor($field) }}" ref="field-{{ $field['key'] }}" />
                @endif

                @if (filled($field['help']))
                    <native:text class="text-sm text-theme-on-surface-variant">{{ $field['help'] }}</native:text>
                @endif

                @isset($errors[$field['key']])
                    <native:text class="text-sm text-theme-destructive">{{ $errors[$field['key']] }}</native:text>
                @endisset
            @endforeach

            <outlined-text-input label="Name (optional)" native:model="label" ref="field-label" />
            @isset($errors['label'])
                <native:text class="text-sm text-theme-destructive">{{ $errors['label'] }}</native:text>
            @endisset

            <native:column ref="channel-actions" class="w-full gap-3">
                @if ($feedback)
                    <native:text ref="channel-feedback" class="text-sm text-theme-destructive">{{ $feedback }}</native:text>
                @endif

                <native:button label="Send code" @tap="send" ref="send-code" class="w-full" />
                <native:button label="Choose another type" variant="secondary" @tap="chooseAnother" class="w-full" />
            </native:column>
        @endif
    </native:column>
</native:scroll-view>
```

`routes/mobile.php`: add `use App\NativeComponents\ChannelCreator;` and, directly after `Route::native('/channels', …)` (it must come before the `{id}` route added in Task 9):

```php
Route::native('/channels/new', ChannelCreator::class);
```

- [ ] **Step 4: Run** `vendor/bin/pest tests/Feature/ChannelCreatorTest.php`, `php artisan view:clear`, `php artisan native:validate`. Expected: PASS. If the `secure` assertion fails because the wire prop has another name, print `$input['props']` once (`->assertElement('outlined_text_input', fn ($i) => dd($i))`) and assert on the real key; the requirement is that the webhook URL field is a masked input. If a dynamic `native:model="values.{{ … }}"` is rejected by `native:validate`, report it as BLOCKED rather than changing the approach.

- [ ] **Step 5: Format and commit**

```bash
cd mobile && vendor/bin/pint --dirty --format agent && cd ..
git add mobile/app mobile/resources mobile/routes mobile/tests
git commit -m "App: add-a-channel screen driven by the server's field descriptors

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 9: The channel screen (confirm, rename, test, delete)

**Files (under `mobile/`):**
- Create: `app/NativeComponents/ChannelEditor.php`, `resources/views/native/channel-editor.blade.php`
- Modify: `routes/mobile.php`
- Test: `tests/Feature/ChannelEditorTest.php`

**Interfaces:**
- Consumes: `WxNotifyApi::channels()/verifyChannel()/resendChannelCode()/testChannel()/renameChannel()/deleteChannel()`; `EditsADraft` (declares `$confirmingDelete`, `$confirmingDiscard`, `askDelete()`, `cancelDelete()`, `onBackPressed()`, `discard()`, `keepEditing()`, `signOutLocally()`; the editor supplies `$ready`, `$draft`, `$original`, `isDirty()`).
- Produces: route `/channels/{id}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\NativeComponents\ChannelEditor;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function editorChannelRow(array $overrides = []): array
{
    return [
        'id' => 5, 'label' => 'Team Discord', 'channel_type' => 'discord', 'type_label' => 'Discord', 'destination' => '••••1234',
        'destination_label' => 'Discord webhook', 'verified' => true, 'managed_by_app' => false, 'created_at' => '2026-10-01T12:00:00+00:00',
        ...$overrides,
    ];
}

function channelEditorFakes(array $listed, array $extra = []): void
{
    Http::fake([
        ...$extra,
        'wx.test/api/v1/channels' => Http::response(['data' => $listed]),
    ]);
}

function channelEditorTextsIn(array $node): array
{
    $texts = array_values(array_filter([$node['props']['text'] ?? null, $node['props']['label'] ?? null], 'is_string'));

    foreach ($node['children'] ?? [] as $child) {
        $texts = [...$texts, ...channelEditorTextsIn($child)];
    }

    return $texts;
}

/**
 * Matches the action area beside the buttons when it shows the text.
 */
function inChannelActions(string $text): Closure
{
    return fn (array $node): bool => ($node['ref'] ?? null) === 'channel-actions' && in_array($text, channelEditorTextsIn($node), true);
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('a verified channel shows its name, type and masked destination, Send test and Delete', function () {
    channelEditorFakes([editorChannelRow()]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->assertNavTitle('Channel')
        ->assertSet('draft.label', 'Team Discord')
        ->assertSee('Discord')
        ->assertSee('Discord webhook: ••••1234')
        ->assertSee('Send test')
        ->assertSee('Delete channel')
        ->assertDontSee('We sent a 6-digit code');
});

test('an unverified channel asks for the code it was sent, with Verify and Resend code, and no Send test', function () {
    channelEditorFakes([editorChannelRow(['verified' => false])]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->assertSee('We sent a 6-digit code to this channel.')
        ->assertSee('Verify')
        ->assertSee('Resend code')
        ->assertDontSee('Send test');
});

test('an empty code is refused locally, a wrong one shows the server\'s message, the right one verifies the channel in place', function () {
    channelEditorFakes([editorChannelRow(['verified' => false])], [
        'wx.test/api/v1/channels/5/verify' => fn (Request $r) => $r->data() === ['code' => '123456']
            ? Http::response(['data' => editorChannelRow()])
            : Http::response(['error' => 'invalid_code', 'message' => 'That code didn\'t match, or it expired. Request a new code if you need one.'], 422),
    ]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->tap('Verify')
        ->assertSee('Enter the 6-digit code.')
        ->input('field-code', '000000')
        ->tap('Verify')
        ->assertSee('That code didn\'t match, or it expired.')
        ->input('field-code', '123456')
        ->tap('Verify')
        ->assertSee('Channel verified.')
        ->assertSee('Send test')
        ->assertDontSee('We sent a 6-digit code')
        ->assertSet('code', '');
});

test('resend says a new code was sent, or that it could not be', function () {
    channelEditorFakes([editorChannelRow(['verified' => false])], ['wx.test/api/v1/channels/5/resend' => Http::response(status: 204)]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->tap('Resend code')
        ->assertElement('column', inChannelActions('We sent a new code.'));

    channelEditorFakes([editorChannelRow(['verified' => false])], ['wx.test/api/v1/channels/5/resend' => Http::response(['error' => 'code_not_sent', 'message' => 'x'], 502)]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->tap('Resend code')
        ->assertElement('column', inChannelActions('We couldn\'t send the confirmation code. Please try again in a moment.'));
});

test('Send test reports success or failure beside the buttons', function () {
    channelEditorFakes([editorChannelRow()], ['wx.test/api/v1/channels/5/test' => Http::response(status: 204)]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->tap('Send test')
        ->assertElement('column', inChannelActions('Test notification sent.'));

    channelEditorFakes([editorChannelRow()], ['wx.test/api/v1/channels/5/test' => Http::response(['error' => 'test_failed', 'message' => 'x'], 502)]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->tap('Send test')
        ->assertElement('column', inChannelActions('Couldn\'t send the test notification. Check the channel is still reachable and try again.'));
});

test('renaming sends the new name, remembers it and goes back; a blank name is refused locally', function () {
    channelEditorFakes([editorChannelRow()], ['wx.test/api/v1/channels/5' => Http::response(['data' => editorChannelRow(['label' => 'Ops Discord'])])]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->input('field-label', '   ')
        ->tap('Save')
        ->assertSee('Give this channel a name.')
        ->assertNoNavigation()
        ->input('field-label', 'Ops Discord')
        ->tap('Save')
        ->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->data() === ['label' => 'Ops Discord']);
    expect(collect((new LocalCache)->channels())->firstWhere('id', 5)['label'])->toBe('Ops Discord');
});

test('leaving with an edited name asks to discard, leaving untouched does not', function () {
    channelEditorFakes([editorChannelRow()]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->call('onBackPressed')
        ->assertWentBack();

    Native::test(ChannelEditor::class, ['id' => 5])
        ->input('field-label', 'Changed')
        ->call('onBackPressed')
        ->assertNoNavigation()
        ->assertElement('column', inChannelActions('Discard your changes?'))
        ->tap('Discard changes')
        ->assertWentBack();
});

test('deleting asks first, then removes the channel from the server and the cache and goes back', function () {
    (new LocalCache)->rememberChannels([editorChannelRow(), editorChannelRow(['id' => 6])]);
    channelEditorFakes([editorChannelRow(), editorChannelRow(['id' => 6])], ['wx.test/api/v1/channels/5' => Http::response(status: 204)]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->tap('Delete channel')
        ->assertElement('column', inChannelActions('Delete this channel? This can\'t be undone.'))
        ->tap('Yes, delete')
        ->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/channels/5'));
    expect(collect((new LocalCache)->channels())->pluck('id')->all())->toBe([6]);
});

test('a channel that is an alert\'s only one stays, with the server\'s message', function () {
    channelEditorFakes([editorChannelRow()], ['wx.test/api/v1/channels/5' => Http::response([
        'error' => 'channel_in_use', 'message' => 'Can\'t delete Team Discord. It\'s the only channel for: Storm warnings. Add another channel to those alerts first.', 'alerts' => ['Storm warnings'],
    ], 409)]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->tap('Delete channel')
        ->tap('Yes, delete')
        ->assertNoNavigation()
        ->assertElement('column', inChannelActions('Can\'t delete Team Discord. It\'s the only channel for: Storm warnings. Add another channel to those alerts first.'));
});

test('a channel already deleted elsewhere goes back as if deleted', function () {
    channelEditorFakes([editorChannelRow()], ['wx.test/api/v1/channels/5' => Http::response([], 404)]);

    Native::test(ChannelEditor::class, ['id' => 5])->tap('Delete channel')->tap('Yes, delete')->assertWentBack();
});

test('offline or in maintenance a write says so beside the buttons and keeps the edit', function () {
    channelEditorFakes([editorChannelRow()]);
    $screen = Native::test(ChannelEditor::class, ['id' => 5])->input('field-label', 'Changed');

    $this->network->connected = false;
    $screen->tap('Save')
        ->assertElement('column', inChannelActions('You\'re offline. Reconnect to save.'))
        ->assertSet('draft.label', 'Changed')
        ->assertNoNavigation();

    $this->network->connected = true;
    Http::fake(['wx.test/api/v1/channels/5' => Http::response('Back soon.', 503, ['Content-Type' => 'text/plain'])]);
    $screen->tap('Save')->assertElement('column', inChannelActions('Back soon.'))->assertSet('draft.label', 'Changed');
});

test('offline it opens from the remembered list, and with nothing remembered it says to connect', function () {
    (new LocalCache)->rememberChannels([editorChannelRow()]);
    $this->network->connected = false;

    Native::test(ChannelEditor::class, ['id' => 5])->assertSet('draft.label', 'Team Discord');

    (new LocalCache)->wipe();

    Native::test(ChannelEditor::class, ['id' => 5])->assertSee('Connect to the internet to open this channel.')->assertSee('Try again');
});

test('a channel that no longer exists, and a phone\'s own channel, are not opened', function () {
    channelEditorFakes([editorChannelRow(['id' => 7])]);
    Native::test(ChannelEditor::class, ['id' => 5])->assertSee('This channel no longer exists.')->assertDontSee('Delete channel');

    channelEditorFakes([editorChannelRow(['managed_by_app' => true, 'channel_type' => 'mobile_push', 'type_label' => 'Mobile Push'])]);
    Native::test(ChannelEditor::class, ['id' => 5])->assertSee('managed in Account')->assertDontSee('Delete channel');
});

test('a rejected token signs out', function () {
    Http::fake(['wx.test/api/v1/channels' => Http::response([], 401)]);

    Native::test(ChannelEditor::class, ['id' => 5])->assertReplacedWith('/sign-in');
});
```

- [ ] **Step 2: Run it to see it fail.**

- [ ] **Step 3: Implement**

`mobile/app/NativeComponents/ChannelEditor.php`:

```php
<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\CodeNotSent;
use App\Exceptions\Api\Conflict;
use App\Exceptions\Api\InvalidCode;
use App\Exceptions\Api\Maintenance;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Rejected;
use App\Exceptions\Api\TestFailed;
use App\Exceptions\Api\Unauthenticated;
use App\Exceptions\Api\ValidationFailed;
use App\Services\WxNotifyApi;
use App\Support\Concerns\EditsADraft;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use Native\Mobile\Edge\NativeComponent;

/**
 * One channel: confirm it with the code it was sent (resending if needed), rename it, send a test
 * through it, or delete it. The destination is never shown beyond its masked summary. A rename is
 * a draft held here until Save. The channel is found in the channels list (there is no single-channel
 * endpoint), from the server or, offline, the remembered copy.
 */
class ChannelEditor extends NativeComponent
{
    use EditsADraft;
    use RequiresSignIn;

    public ?int $channelId = null;

    /** @var array<string, mixed> */
    public array $channel = [];

    /** @var array<string, string> */
    public array $draft = ['label' => ''];

    /** @var array<string, string> */
    public array $original = [];

    /** @var array<string, string> */
    public array $errors = [];

    public string $code = '';

    public ?string $codeError = null;

    /** A failure, shown beside the buttons (or why the channel could not be opened). */
    public ?string $banner = null;

    /** A success ("Channel verified.", "Test notification sent."), shown beside the buttons. */
    public ?string $info = null;

    public bool $ready = false;

    public bool $canRetry = false;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $this->channelId = (int) $this->param('id');

        $this->load();
    }

    public function load(): void
    {
        $this->banner = null;
        $this->canRetry = false;

        $channel = $this->loadChannel($this->channelId);

        if ($channel === null) {
            return;
        }

        if ($channel['managed_by_app']) {
            $this->banner = 'This channel is a phone\'s push notifications, managed in Account.';

            return;
        }

        $this->channel = $channel;
        $this->draft = ['label' => (string) $channel['label']];
        $this->original = $this->draft;
        $this->ready = true;
    }

    public function updatedDraft(mixed $value, ?string $key = null): void
    {
        unset($this->errors['label']);
    }

    public function updatedCode(): void
    {
        $this->codeError = null;
    }

    // ---- actions -------------------------------------------------------------------------------

    public function save(): void
    {
        $this->banner = null;
        $this->info = null;
        $this->errors = [];

        if (trim($this->draft['label']) === '') {
            $this->errors['label'] = 'Give this channel a name.';

            return;
        }

        try {
            $channel = app(WxNotifyApi::class)->renameChannel($this->channelId, trim($this->draft['label']));
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (ValidationFailed $e) {
            $this->errors['label'] = $e->errors['label'] ?? 'That name can\'t be used.';

            return;
        } catch (Conflict $e) {
            $this->banner = $e->getMessage() !== '' ? $e->getMessage() : 'This channel can\'t be renamed.';

            return;
        } catch (Rejected) {
            $this->banner = 'This channel no longer exists.';

            return;
        } catch (Maintenance $e) {
            $this->banner = $e->getMessage();

            return;
        } catch (Offline) {
            $this->banner = 'You\'re offline. Reconnect to save.';

            return;
        } catch (ApiException) {
            $this->banner = 'Couldn\'t save the channel. Please try again.';

            return;
        }

        $this->rememberChannel($channel);
        $this->original = $this->draft;
        $this->back();
    }

    public function verify(): void
    {
        $this->banner = null;
        $this->info = null;
        $this->codeError = null;
        $code = trim($this->code);

        if ($code === '') {
            $this->codeError = 'Enter the 6-digit code.';

            return;
        }

        try {
            $channel = app(WxNotifyApi::class)->verifyChannel($this->channelId, $code);
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (InvalidCode $e) {
            $this->codeError = $e->getMessage() !== '' ? $e->getMessage() : 'That code didn\'t match, or it expired. Request a new code if you need one.';

            return;
        } catch (Conflict $e) {
            $this->banner = $e->getMessage() !== '' ? $e->getMessage() : 'This channel can\'t be verified.';

            return;
        } catch (Rejected) {
            $this->banner = 'This channel no longer exists.';

            return;
        } catch (Maintenance $e) {
            $this->banner = $e->getMessage();

            return;
        } catch (Offline) {
            $this->banner = 'You\'re offline. Reconnect to verify.';

            return;
        } catch (ApiException) {
            $this->banner = 'Couldn\'t verify the channel. Please try again.';

            return;
        }

        $this->channel = $channel;
        $this->code = '';
        $this->info = 'Channel verified.';
        $this->rememberChannel($channel);
    }

    public function resend(): void
    {
        $this->banner = null;
        $this->info = null;
        $this->codeError = null;

        try {
            app(WxNotifyApi::class)->resendChannelCode($this->channelId);
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (CodeNotSent) {
            $this->banner = 'We couldn\'t send the confirmation code. Please try again in a moment.';

            return;
        } catch (Conflict $e) {
            $this->banner = $e->getMessage() !== '' ? $e->getMessage() : 'A new code can\'t be sent for this channel.';

            return;
        } catch (Rejected) {
            $this->banner = 'This channel no longer exists.';

            return;
        } catch (Maintenance $e) {
            $this->banner = $e->getMessage();

            return;
        } catch (Offline) {
            $this->banner = 'You\'re offline. Reconnect to resend the code.';

            return;
        } catch (ApiException) {
            $this->banner = 'Couldn\'t send a new code. Please try again.';

            return;
        }

        $this->info = 'We sent a new code.';
    }

    public function sendTest(): void
    {
        $this->banner = null;
        $this->info = null;

        try {
            app(WxNotifyApi::class)->testChannel($this->channelId);
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (TestFailed) {
            $this->banner = 'Couldn\'t send the test notification. Check the channel is still reachable and try again.';

            return;
        } catch (Conflict $e) {
            $this->banner = $e->getMessage() !== '' ? $e->getMessage() : 'A test can\'t be sent through this channel.';

            return;
        } catch (Rejected) {
            $this->banner = 'This channel no longer exists.';

            return;
        } catch (Maintenance $e) {
            $this->banner = $e->getMessage();

            return;
        } catch (Offline) {
            $this->banner = 'You\'re offline. Reconnect to send a test.';

            return;
        } catch (ApiException) {
            $this->banner = 'Couldn\'t send the test notification. Please try again.';

            return;
        }

        $this->info = 'Test notification sent.';
    }

    /**
     * A refusal keeps the channel and shows the server's own message (a channel that is the only one
     * for some alerts names the first few of them).
     */
    public function delete(): void
    {
        $this->banner = null;
        $this->info = null;

        try {
            app(WxNotifyApi::class)->deleteChannel($this->channelId);
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (Conflict $e) {
            $this->confirmingDelete = false;
            $this->banner = $e->getMessage() !== '' ? $e->getMessage() : 'This channel can\'t be deleted right now.';

            return;
        } catch (Rejected) {
            // Already gone elsewhere: the list should drop it too.
        } catch (Maintenance $e) {
            $this->confirmingDelete = false;
            $this->banner = $e->getMessage();

            return;
        } catch (Offline) {
            $this->confirmingDelete = false;
            $this->banner = 'You\'re offline. Reconnect to delete this channel.';

            return;
        } catch (ApiException) {
            $this->confirmingDelete = false;
            $this->banner = 'Couldn\'t delete the channel. Please try again.';

            return;
        }

        $cache = app(LocalCache::class);
        $cache->rememberChannels(array_values(array_filter($cache->channels() ?? [], fn (array $channel): bool => $channel['id'] !== $this->channelId)));

        $this->original = $this->draft;
        $this->back();
    }

    public function isDirty(): bool
    {
        return $this->draft !== $this->original;
    }

    public function isVerified(): bool
    {
        return (bool) ($this->channel['verified'] ?? false);
    }

    // ---- helpers -------------------------------------------------------------------------------

    /**
     * The channel from the server's list, or the remembered copy when the server cannot be reached.
     *
     * @return array<string, mixed>|null the channel, or null with a banner (or a sign-out) when it cannot be opened
     */
    private function loadChannel(int $id): ?array
    {
        $cache = app(LocalCache::class);
        $cached = collect($cache->channels() ?? [])->firstWhere('id', $id);

        try {
            $channels = app(WxNotifyApi::class)->channels();
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return null;
        } catch (ApiException $e) {
            if ($cached === null) {
                $this->banner = match (true) {
                    $e instanceof Offline => 'Connect to the internet to open this channel.',
                    $e instanceof Maintenance => $e->getMessage(),
                    default => 'WxNotify could not load this channel. Try again in a moment.',
                };
                $this->canRetry = true;
            }

            return $cached;
        }

        $cache->rememberChannels($channels);
        $channel = collect($channels)->firstWhere('id', $id);

        if ($channel === null) {
            $this->banner = 'This channel no longer exists.';
        }

        return $channel;
    }

    /**
     * @param  array<string, mixed>  $channel
     */
    private function rememberChannel(array $channel): void
    {
        $cache = app(LocalCache::class);

        $cache->rememberChannels(array_values(array_map(
            fn (array $listed): array => $listed['id'] === ($channel['id'] ?? null) ? $channel : $listed,
            $cache->channels() ?? [],
        )));
    }
}
```

`mobile/resources/views/native/channel-editor.blade.php`:

```blade
{{-- Un-prefixed on purpose: native:validate does not know <outlined-text-input>. --}}

<native:top-bar title="Channel" show-navigation-icon="true"></native:top-bar>

<native:scroll-view class="w-full h-full bg-theme-background">
    <native:column class="w-full p-4 gap-4">
        @if ($banner && ! $ready)
            <native:text class="text-sm text-theme-destructive">{{ $banner }}</native:text>
        @endif

        @if ($canRetry)
            <native:button label="Try again" variant="secondary" @tap="load" ref="retry-channel" class="w-full" />
        @endif

        @if ($ready)
            <native:text class="text-sm text-theme-on-surface-variant">{{ $channel['type_label'] }}</native:text>
            @if (filled($channel['destination'] ?? null))
                <native:text class="text-sm text-theme-on-surface-variant">{{ $channel['destination_label'] }}: {{ $channel['destination'] }}</native:text>
            @endif

            <outlined-text-input label="Name" native:model="draft.label" ref="field-label" />
            @isset($errors['label'])
                <native:text class="text-sm text-theme-destructive">{{ $errors['label'] }}</native:text>
            @endisset

            @unless ($this->isVerified())
                <native:text class="text-lg font-bold text-theme-on-background">Confirm this channel</native:text>
                <native:text class="text-base text-theme-on-surface-variant">We sent a 6-digit code to this channel. Enter it to start receiving alerts here.</native:text>
                <outlined-text-input label="Confirmation code" native:model="code" keyboard="number" ref="field-code" />
                @if ($codeError)
                    <native:text class="text-sm text-theme-destructive">{{ $codeError }}</native:text>
                @endif
                <native:button label="Verify" @tap="verify" ref="verify-channel" class="w-full" />
                <native:button label="Resend code" variant="secondary" @tap="resend" ref="resend-code" class="w-full" />
            @endunless

            {{-- The save/test/delete feedback and the confirmations sit right above the buttons that lead to them. --}}
            <native:column ref="channel-actions" class="w-full gap-3">
                @if ($banner)
                    <native:text ref="channel-feedback" class="text-sm text-theme-destructive">{{ $banner }}</native:text>
                @endif

                @if ($info)
                    <native:text ref="channel-info" class="text-sm text-theme-on-surface-variant">{{ $info }}</native:text>
                @endif

                @if ($confirmingDiscard)
                    <native:column class="w-full p-3 gap-2 bg-theme-surface">
                        <native:text class="text-base text-theme-on-background">Discard your changes?</native:text>
                        <native:button label="Discard changes" @tap="discard" class="w-full" />
                        <native:button label="Keep editing" variant="secondary" @tap="keepEditing" class="w-full" />
                    </native:column>
                @endif

                @if ($confirmingDelete)
                    <native:column class="w-full p-3 gap-2 bg-theme-surface">
                        <native:text class="text-base text-theme-on-background">Delete this channel? This can't be undone.</native:text>
                        <native:button label="Yes, delete" @tap="delete" class="w-full" />
                        <native:button label="Keep channel" variant="secondary" @tap="cancelDelete" class="w-full" />
                    </native:column>
                @endif

                <native:button label="Save" @tap="save" ref="save-channel" class="w-full" />

                @if ($this->isVerified())
                    <native:button label="Send test" variant="secondary" @tap="sendTest" ref="send-test" class="w-full" />
                @endif

                @unless ($confirmingDelete)
                    <native:button label="Delete channel" variant="secondary" @tap="askDelete" ref="delete-channel" class="w-full" />
                @endunless
            </native:column>
        @endif
    </native:column>
</native:scroll-view>
```

The test's `assertElement('column', inChannelActions(...))` needs the banner and info to be inside the `channel-actions` column, as above; the "We sent a 6-digit code" and code-error texts are NOT in it.

`routes/mobile.php`: add `use App\NativeComponents\ChannelEditor;` and, after the `/channels/new` route:

```php
Route::native('/channels/{id}', ChannelEditor::class);
```

- [ ] **Step 4: Run** `vendor/bin/pest tests/Feature/ChannelEditorTest.php`, then `php artisan view:clear && php artisan native:validate`. Expected: PASS. Two spots may need adjusting to the harness without changing the behaviour: (a) `tap('Save')` can match both the "Save" button and nothing else, but if a text such as "Saved" ever appears use `tap('save-channel')`; (b) `keyboard="number"` is a documented keyboard hint (`text|number|email|phone|url|decimal|password`).

- [ ] **Step 5: Format and commit**

```bash
cd mobile && vendor/bin/pint --dirty --format agent && cd ..
git add mobile/app mobile/resources mobile/routes mobile/tests
git commit -m "App: channel screen to confirm, rename, test and delete a channel

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Whole-slice verification and notes

**Files:**
- Modify: `docs/superpowers/specs/2026-10-10-mobile-channels-design.md` only if a decision changed during the build (record it under a "Clarifications settled while building" heading).
- Create: `<Claude project memory>/mobile-channels-state.md` and one line in `MEMORY.md`.

- [ ] **Step 1: Run the full backend suite in parallel**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --parallel --processes=6'
```

Expected: all pass. Any failure in a channel or Filament test means the Task 2 refactor changed dashboard behaviour: fix it there.

- [ ] **Step 2: Run the full app suite and validation**

```bash
cd mobile && vendor/bin/pest && php artisan view:clear && php artisan native:validate
```

Expected: all pass; "All components passed validation".

- [ ] **Step 3: Write the memory note** `mobile-channels-state.md` (frontmatter `name: mobile-channels-state`, `type: project`): slice 4b built on `main` (commit range, test counts), what exists (the endpoints, `BeginChannelConnection`, `appSetupFields`, the three screens), the device check still to do on the Android emulator against the dev server once pushed (list, add an Email channel and confirm it, wrong code, resend, rename and discard prompt, send test, delete and the only-channel guard, offline), unverified items (iOS, a real ntfy/Discord send), and the two follow-ups from the spec (ntfy server URL is not SSRF-guarded; no per-destination limit on email). Add `- [Mobile channels state](mobile-channels-state.md) — slice 4b built on main; device check pending` to `MEMORY.md`.

- [ ] **Step 4: Commit any spec change** (skip when nothing changed). Do not push.
