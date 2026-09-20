# Tier 1 Notification Channels Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Pushover, Microsoft Teams, Custom Webhooks and Web Push to the channel picker with the same create → confirmation-code flow as the existing channels.

**Architecture:** Pushover and Teams are new Apprise URL types. Custom Webhooks and Web Push need a delivery layer that isn't tied to Apprise: a `ChannelDispatcher` picks a transport (`apprise`, `webhook`, `webpush`) from the channel type, and the delivery jobs plus the confirmation-code action call it instead of `AppriseApiClient`. Non-Apprise settings live in a new encrypted `notification_channels.config` column; `apprise_url` becomes nullable. Both non-Apprise transports send to subscriber-controlled URLs, so a shared SSRF guard validates them at save time and again at send time.

**Tech Stack:** Laravel 13 / PHP 8.5, Filament v5, Pest + Livewire, Apprise API v1.13, `spatie/laravel-webhook-server` (approved, new), `laravel-notification-channels/webpush` (approved, new; pulls in `minishlink/web-push`).

**Spec:** `docs/superpowers/specs/2026-09-20-tier1-notification-channels-design.md`

## Global Constraints

- Only two new Composer dependencies are approved: `spatie/laravel-webhook-server` and `laravel-notification-channels/webpush` (dry-run resolved cleanly on this Laravel 13 / PHP 8.5 install: webhook-server 3.11.0, webpush 13.0.1 + minishlink/web-push 11.0.0). Add nothing else.
- All four new channels are free-tier (`min_tier = free`) and `countsTowardFreeSlot() === true`.
- `apprise_url` and `config` are encrypted and must never appear in activity logs, application logs, notifications or exception messages shown to the subscriber.
- Custom webhook URLs must be `https://`, and both webhook URLs and Web Push endpoints must pass the SSRF guard at save time AND at send time. Self-hosted instances may allow private networks only via `config('wxnotify.webhooks.allow_private_networks')` and only when `config('wxnotify.mode') === 'selfhost'`.
- New channel types are registered in `ChannelTypeRegistry::$classes`, inserted into `channel_types` by a migration, and appended to `Database\Seeders\ChannelTypeSeeder::SHIPPED` (Reset to Factory Defaults and `ChannelTypeSeederTest` rely on that list matching the migrated table, ordered by `sort_order`).
- `SendActivityAlert` is NOT changed: it sends to an admin-configured `apprise_target` on an `ActivityAlertRule`, not to a `NotificationChannel`, and keeps using `AppriseApiClient`.
- NEVER run `migrate:fresh`, `migrate:refresh`, `migrate:reset` or `db:seed` against the dev DB. Run tests only inside the Sail container: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest <path> --compact"`.
- Read `.ai/rules/index.md` and every rule file matching the paths you edit (`app/Filament/**`, `app/Models/**`, `tests/Feature/**`). Pest helper function names are global: prefix any helper you define (`chan…`). Known Filament traps: use `assertFormFieldVisible/Hidden`; wizard tests must use the existing `goToConnectChannelStep()` helper in `tests/Feature/ChannelConnectionTest.php`; never chain `->hidden()->visible()`.
- Run `vendor/bin/pint --dirty --format agent` on the host before each commit. Commit messages end with the line `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`. Do not push. The uncommitted edit to `app/Filament/Admin/Resources/IngestedProducts/Tables/IngestedProductsTable.php` is not part of this work: leave it unstaged.

---

## File Structure

- Create: `app/Enums/ChannelTransport.php`, `app/Support/Delivery/{DeliveryResult,ChannelDispatcher,WebhookUrlGuard}.php`, `app/Support/Delivery/Transports/{Transport,AppriseTransport,WebhookTransport,WebPushTransport}.php`, `app/Support/ChannelTypes/{PushoverChannelType,TeamsChannelType,WebhookChannelType,WebPushChannelType,Concerns/UsesAppriseTransport}.php`, `app/Rules/SafeWebhookUrl.php`
- Create (browser): `public/dashboard-sw.js`, `resources/views/filament/dashboard/channels/web-push-setup.blade.php`
- Create (migrations, dates in order): `2026_09_20_100001_add_pushover_and_msteams_channel_types.php`, `2026_09_20_100002_add_config_to_notification_channels_and_make_apprise_url_nullable.php`, `2026_09_20_100003_add_webhook_channel_type.php`, `2026_09_20_100004_add_webpush_channel_type.php`
- Modify: `app/Support/ChannelTypes/{ChannelTypeRegistry,Contracts/ChannelType,DiscordChannelType,EmailChannelType,NtfyChannelType,SlackChannelType,TelegramChannelType}.php`, `app/Models/NotificationChannel.php`, `app/Jobs/{DeliverWeatherAlert,DeliverScheduledAlert}.php`, `app/Actions/Channels/DispatchChannelConfirmationCode.php`, `app/Filament/Dashboard/Pages/ConnectChannel.php`, `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php`, `app/Filament/Dashboard/Resources/NotificationChannels/Schemas/NotificationChannelForm.php`, `database/seeders/ChannelTypeSeeder.php`, `database/factories/NotificationChannelFactory.php`, `config/wxnotify.php`, `.env.example`, `composer.json`/`composer.lock`
- Tests: `tests/Feature/{ChannelTypesTest,ChannelConnectionTest,ChannelTypeSeederTest,DeliverWeatherAlertTest,DeliverScheduledAlertTest,ChannelDispatcherTest,WebhookUrlGuardTest,WebhookChannelTest,WebPushChannelTest}.php`

---

## Stage 1 — Pushover and Microsoft Teams (Apprise types)

### Task 1: Pushover + Teams channel types

**Files:**
- Create: `app/Support/ChannelTypes/PushoverChannelType.php`, `app/Support/ChannelTypes/TeamsChannelType.php`, `database/migrations/2026_09_20_100001_add_pushover_and_msteams_channel_types.php`
- Modify: `app/Support/ChannelTypes/ChannelTypeRegistry.php`, `database/seeders/ChannelTypeSeeder.php`, `tests/Feature/ChannelTypesTest.php`, `tests/Feature/ChannelConnectionTest.php`

**Interfaces:**
- Consumes: `App\Support\ChannelTypes\Contracts\ChannelType` (current shape: `slug()`, `label()`, `icon()`, `formSchema(): array<Field>`, `buildAppriseUrl(array): string`, `countsTowardFreeSlot(): bool`).
- Produces: slugs `pushover` and `msteams` in the registry and `channel_types` (`sort_order` 6 and 7). `TeamsChannelType::parseWorkflowUrl(string $url): ?array{host: string, workflow: string, signature: string}` (public static).

- [ ] **Step 1: Write the failing tests.** Append to `tests/Feature/ChannelTypesTest.php` (add `use App\Support\ChannelTypes\ChannelTypeRegistry;`, `PushoverChannelType`, `TeamsChannelType` imports):

```php
test('pushover builds a pover url from user key and application token', function () {
    $url = (new PushoverChannelType)->buildAppriseUrl([
        'user_key' => 'abcdefghijklmnopqrstuvwxyz1234',
        'api_token' => 'zyxwvutsrqponmlkjihgfedcba4321',
    ]);

    expect($url)->toBe('pover://abcdefghijklmnopqrstuvwxyz1234@zyxwvutsrqponmlkjihgfedcba4321');
});

test('teams converts a power automate workflows url to an apprise workflows url', function () {
    $url = (new TeamsChannelType)->buildAppriseUrl([
        'webhook_url' => 'https://prod-12.westus.logic.azure.com:443/workflows/0123456789abcdef0123456789abcdef/triggers/manual/paths/invoke?api-version=2016-06-01&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=SIGNATUREabc-_123',
    ]);

    expect($url)->toBe('workflows://prod-12.westus.logic.azure.com/0123456789abcdef0123456789abcdef/SIGNATUREabc-_123');
});

test('teams keeps a non-default port', function () {
    $url = (new TeamsChannelType)->buildAppriseUrl([
        'webhook_url' => 'https://prod-12.westus.logic.azure.com:8443/workflows/0123456789abcdef0123456789abcdef/triggers/manual/paths/invoke?sig=abc123',
    ]);

    expect($url)->toBe('workflows://prod-12.westus.logic.azure.com:8443/0123456789abcdef0123456789abcdef/abc123');
});

test('teams rejects retired office 365 connector urls and other hosts', function (string $url) {
    expect(TeamsChannelType::parseWorkflowUrl($url))->toBeNull();
})->with([
    'legacy connector' => 'https://contoso.webhook.office.com/webhookb2/aaaa@bbbb/IncomingWebhook/cccc/dddd',
    'outlook connector' => 'https://outlook.office.com/webhook/aaaa@bbbb/IncomingWebhook/cccc/dddd',
    'wrong host' => 'https://example.com/workflows/0123456789abcdef0123456789abcdef/triggers/manual/paths/invoke?sig=abc',
    'http' => 'http://prod-12.westus.logic.azure.com/workflows/0123456789abcdef0123456789abcdef/triggers/manual/paths/invoke?sig=abc',
    'no signature' => 'https://prod-12.westus.logic.azure.com/workflows/0123456789abcdef0123456789abcdef/triggers/manual/paths/invoke',
]);

test('the picker offers pushover and teams and they count toward the free slot', function () {
    $slugs = app(ChannelTypeRegistry::class)->forPicker()->map(fn ($row) => $row['type']->slug())->all();

    expect($slugs)->toContain('pushover')->toContain('msteams')
        ->and((new PushoverChannelType)->countsTowardFreeSlot())->toBeTrue()
        ->and((new TeamsChannelType)->countsTowardFreeSlot())->toBeTrue();
});

test('pushover validates the key and token shape', function () {
    $fields = collect((new PushoverChannelType)->formSchema())->keyBy(fn ($f) => $f->getName());

    expect($fields->keys()->all())->toBe(['user_key', 'api_token']);

    foreach (['user_key', 'api_token'] as $name) {
        $validator = Validator::make([$name => 'too-short'], [$name => $fields[$name]->getValidationRules()]);
        expect($validator->fails())->toBeTrue();

        $validator = Validator::make([$name => 'abcdefghijklmnopqrstuvwxyz1234'], [$name => $fields[$name]->getValidationRules()]);
        expect($validator->passes())->toBeTrue();
    }
});
```
(add `use Illuminate\Support\Facades\Validator;`). In `tests/Feature/ChannelConnectionTest.php` append, mirroring the existing ntfy wizard test:

```php
test('completing the wizard for pushover stores the pover url', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'pushover']);
    goToConnectChannelStep($component, 0);
    $component->assertWizardCurrentStep(2)
        ->fillForm(['user_key' => 'abcdefghijklmnopqrstuvwxyz1234', 'api_token' => 'zyxwvutsrqponmlkjihgfedcba4321']);
    goToConnectChannelStep($component, 1);
    $component->assertWizardCurrentStep(3);

    $channel = NotificationChannel::where('user_id', $this->user->id)->sole();

    expect($channel->channel_type)->toBe('pushover')
        ->and($channel->apprise_url)->toBe('pover://abcdefghijklmnopqrstuvwxyz1234@zyxwvutsrqponmlkjihgfedcba4321');
});

test('completing the wizard for teams stores the workflows url', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'msteams']);
    goToConnectChannelStep($component, 0);
    $component->assertWizardCurrentStep(2)->fillForm([
        'webhook_url' => 'https://prod-12.westus.logic.azure.com:443/workflows/0123456789abcdef0123456789abcdef/triggers/manual/paths/invoke?api-version=2016-06-01&sig=SIGNATUREabc-_123',
    ]);
    goToConnectChannelStep($component, 1);
    $component->assertWizardCurrentStep(3);

    expect(NotificationChannel::where('user_id', $this->user->id)->sole()->apprise_url)
        ->toBe('workflows://prod-12.westus.logic.azure.com/0123456789abcdef0123456789abcdef/SIGNATUREabc-_123');
});
```
Update `tests/Feature/ChannelTypeSeederTest.php` only if needed: it already compares the migrated table with `SHIPPED`, so it will fail until Steps 3–4 are done — that is the intended red state.

- [ ] **Step 2: Run to verify failure.** `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ChannelTypesTest.php tests/Feature/ChannelConnectionTest.php --compact"` → FAIL (classes missing).

- [ ] **Step 3: Implement the types.**

`app/Support/ChannelTypes/PushoverChannelType.php`:
```php
<?php

namespace App\Support\ChannelTypes;

use App\Support\ChannelTypes\Contracts\ChannelType;
use Filament\Forms\Components\TextInput;

class PushoverChannelType implements ChannelType
{
    public function slug(): string
    {
        return 'pushover';
    }

    public function label(): string
    {
        return 'Pushover';
    }

    public function icon(): string
    {
        return 'heroicon-o-device-phone-mobile';
    }

    public function formSchema(): array
    {
        return [
            TextInput::make('user_key')
                ->label('User key')
                ->required()
                ->regex('/^[A-Za-z0-9]{30}$/')
                ->validationMessages(['regex' => 'A Pushover user key is 30 letters and numbers.'])
                ->helperText('Shown at the top of your Pushover dashboard (pushover.net).'),
            TextInput::make('api_token')
                ->label('Application token')
                ->required()
                ->password()
                ->revealable()
                ->regex('/^[A-Za-z0-9]{30}$/')
                ->validationMessages(['regex' => 'A Pushover application token is 30 letters and numbers.'])
                ->helperText('Create an application at pushover.net/apps and copy its API token.'),
        ];
    }

    public function buildAppriseUrl(array $data): string
    {
        return "pover://{$data['user_key']}@{$data['api_token']}";
    }

    public function countsTowardFreeSlot(): bool
    {
        return true;
    }
}
```
`app/Support/ChannelTypes/TeamsChannelType.php`:
```php
<?php

namespace App\Support\ChannelTypes;

use App\Support\ChannelTypes\Contracts\ChannelType;
use Closure;
use Filament\Forms\Components\TextInput;

class TeamsChannelType implements ChannelType
{
    public function slug(): string
    {
        return 'msteams';
    }

    public function label(): string
    {
        return 'Microsoft Teams';
    }

    public function icon(): string
    {
        return 'fab-microsoft';
    }

    public function formSchema(): array
    {
        return [
            TextInput::make('webhook_url')
                ->label('Workflows webhook URL')
                ->url()
                ->required()
                ->helperText('In Teams: Workflows → "Post to a channel when a webhook request is received", then copy the HTTP POST URL. Microsoft retired the old Office 365 connector webhooks, so those URLs no longer work.')
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (self::parseWorkflowUrl((string) $value) === null) {
                        $fail('That is not a Power Automate Workflows webhook URL (https://…logic.azure.com/workflows/…&sig=…).');
                    }
                }),
        ];
    }

    /**
     * @return ?array{host: string, workflow: string, signature: string}
     */
    public static function parseWorkflowUrl(string $url): ?array
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])) {
            return null;
        }

        if (! str_ends_with(strtolower($parts['host']), '.logic.azure.com')) {
            return null;
        }

        if (! preg_match('#/workflows/([0-9a-f]{32})/#i', $parts['path'] ?? '', $path)) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);
        $signature = $query['sig'] ?? null;

        if (! is_string($signature) || ! preg_match('/^[A-Za-z0-9_-]+$/', $signature)) {
            return null;
        }

        $port = $parts['port'] ?? 443;

        return [
            'host' => strtolower($parts['host']).($port === 443 ? '' : ":{$port}"),
            'workflow' => strtolower($path[1]),
            'signature' => $signature,
        ];
    }

    public function buildAppriseUrl(array $data): string
    {
        $parsed = self::parseWorkflowUrl($data['webhook_url']);

        return "workflows://{$parsed['host']}/{$parsed['workflow']}/{$parsed['signature']}";
    }

    public function countsTowardFreeSlot(): bool
    {
        return true;
    }
}
```
Register both in `ChannelTypeRegistry::$classes` (`'pushover' => PushoverChannelType::class, 'msteams' => TeamsChannelType::class`). Confirm the Teams mapping against the running Apprise: `docker exec wxnotify-nextgen-apprise-1 sh -c "apprise -vv -t t -b b --dry-run 'workflows://prod-12.westus.logic.azure.com/0123456789abcdef0123456789abcdef/SIGNATUREabc-_123'"` must print a parsed `workflow://…` entry (verified once already while writing this plan; `pover://KEY@TOKEN` also parses; `msteams://` does NOT parse in Apprise v1.13, which is why Teams uses `workflows://`). Confirm `fab-microsoft` exists: `ls vendor/owenvoke/blade-fontawesome/resources/svg/brands | grep microsoft`; if not, use `heroicon-o-building-office`.

- [ ] **Step 4: Catalog.** `database/migrations/2026_09_20_100001_add_pushover_and_msteams_channel_types.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([['pushover', 6], ['msteams', 7]] as [$slug, $order]) {
            if (! DB::table('channel_types')->where('slug', $slug)->exists()) {
                DB::table('channel_types')->insert([
                    'slug' => $slug, 'enabled' => true, 'min_tier' => 'free', 'sort_order' => $order,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('channel_types')->whereIn('slug', ['pushover', 'msteams'])->delete();
    }
};
```
Append to `ChannelTypeSeeder::SHIPPED`: `['slug' => 'pushover', 'min_tier' => 'free', 'sort_order' => 6, 'enabled' => true], ['slug' => 'msteams', 'min_tier' => 'free', 'sort_order' => 7, 'enabled' => true],`.

- [ ] **Step 5: Run** the two test files plus `ChannelTypeSeederTest`, `FactoryResetActionsTest` and `NotificationChannelResourceTest` → PASS. Run pint. Mutation-check: make `TeamsChannelType::parseWorkflowUrl` skip the host check → the "wrong host" case must fail; restore.
- [ ] **Step 6: Commit** `git add app database tests && git commit -m "Add Pushover and Microsoft Teams channel types"`.

---

## Stage 2 — Delivery layer and Custom Webhooks

### Task 2: Channel dispatcher and non-Apprise storage

**Files:**
- Create: `app/Enums/ChannelTransport.php`, `app/Support/Delivery/DeliveryResult.php`, `app/Support/Delivery/ChannelDispatcher.php`, `app/Support/Delivery/Transports/Transport.php`, `app/Support/Delivery/Transports/AppriseTransport.php`, `app/Support/ChannelTypes/Concerns/UsesAppriseTransport.php`, `database/migrations/2026_09_20_100002_add_config_to_notification_channels_and_make_apprise_url_nullable.php`
- Modify: `app/Support/ChannelTypes/Contracts/ChannelType.php`, the seven Apprise type classes (`Discord`, `Email`, `Ntfy`, `Slack`, `Telegram`, `Pushover`, `Teams`), `app/Support/ChannelTypes/ChannelTypeRegistry.php`, `app/Models/NotificationChannel.php`, `app/Jobs/DeliverWeatherAlert.php`, `app/Jobs/DeliverScheduledAlert.php`, `app/Actions/Channels/DispatchChannelConfirmationCode.php`, `app/Filament/Dashboard/Pages/ConnectChannel.php`, `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php`, `tests/Feature/DeliverWeatherAlertTest.php`, `tests/Feature/DeliverScheduledAlertTest.php`
- Test: `tests/Feature/ChannelDispatcherTest.php`

**Interfaces:**
- Produces:
  - `enum App\Enums\ChannelTransport: string { Apprise = 'apprise'; Webhook = 'webhook'; WebPush = 'webpush'; }`
  - `final class DeliveryResult { public function __construct(public readonly bool $success, public readonly ?string $error = null); static delivered(): self; static failed(string $error): self }`
  - `interface Transport { public function send(NotificationChannel $channel, string $body, ?string $title, array $meta = []): DeliveryResult; }`
  - `ChannelDispatcher::send(NotificationChannel $channel, string $body, ?string $title = null, array $meta = []): DeliveryResult` — `$meta` keys: `type` (`'alert'|'confirmation'`), plus type-specific extras (`code`, `rule`, `product`). Apprise ignores `$meta`. A `ConnectionException` from the Apprise client is NOT caught (callers keep their existing handling).
  - Updated `ChannelType` contract (all types): `slug()`, `label()`, `icon()`, `formSchema()`, `countsTowardFreeSlot()`, **`transport(): ChannelTransport`**, **`buildDestination(array $data): array{apprise_url?: string, config?: array<string,mixed>, label?: string}`**, **`supportsInlineCreate(): bool`**, **`isAvailable(): bool`**. `buildAppriseUrl(array): string` remains a plain public method on Apprise types (tests call it directly) but is no longer part of the interface. `UsesAppriseTransport` trait provides `transport()` (Apprise), `buildDestination()` (`['apprise_url' => $this->buildAppriseUrl($data)]`), `supportsInlineCreate()` (true) and `isAvailable()` (true).
  - `NotificationChannel`: `apprise_url` nullable, new `config` attribute cast `encrypted:array`, both fillable.

- [ ] **Step 1: Failing tests.** `tests/Feature/ChannelDispatcherTest.php`:
```php
<?php

use App\Models\NotificationChannel;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\DeliveryResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

test('an apprise channel is delivered through apprise and a 200 is a success', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->create(['channel_type' => 'discord', 'apprise_url' => 'discord://1/abc/']);

    $result = app(ChannelDispatcher::class)->send($channel, 'body', 'title');

    expect($result)->toBeInstanceOf(DeliveryResult::class)
        ->and($result->success)->toBeTrue()->and($result->error)->toBeNull();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/notify/')
        && $request['urls'] === 'discord://1/abc/' && $request['body'] === 'body' && $request['title'] === 'title');
});

test('apprise reporting anything but 200 is a failure with the existing message', function () {
    Http::fake(['*/notify/*' => Http::response(status: 204)]);
    $channel = NotificationChannel::factory()->create(['channel_type' => 'discord']);

    $result = app(ChannelDispatcher::class)->send($channel, 'body');

    expect($result->success)->toBeFalse()->and($result->error)->toBe('Apprise reported non-delivery.');
});

test('a connection exception from apprise still propagates so queued jobs can retry', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('apprise is down')]);
    $channel = NotificationChannel::factory()->create(['channel_type' => 'discord']);

    expect(fn () => app(ChannelDispatcher::class)->send($channel, 'body'))->toThrow(ConnectionException::class);
});

test('an unknown channel type is a failed result, not an exception', function () {
    $channel = NotificationChannel::factory()->create(['channel_type' => 'carrier-pigeon']);

    $result = app(ChannelDispatcher::class)->send($channel, 'body');

    expect($result->success)->toBeFalse()->and($result->error)->toContain('carrier-pigeon');
});

test('config is stored encrypted and is not written to the activity log', function () {
    $channel = NotificationChannel::factory()->create([
        'channel_type' => 'discord', 'apprise_url' => null, 'config' => ['secret' => 'shh-super-secret'],
    ]);

    expect($channel->fresh()->config)->toBe(['secret' => 'shh-super-secret'])
        ->and(DB::table('notification_channels')->where('id', $channel->id)->value('config'))->not->toContain('shh-super-secret');

    $channel->update(['label' => 'renamed']);
    expect(json_encode(Spatie\Activitylog\Models\Activity::all()->toArray()))->not->toContain('shh-super-secret');
});
```
(add `use Illuminate\Support\Facades\DB;`). Update the two existing job test files mechanically: replace `use App\Support\Apprise\AppriseApiClient;` with `use App\Support\Delivery\ChannelDispatcher;` and every `->handle(app(AppriseApiClient::class))` with `->handle(app(ChannelDispatcher::class))` (`sed -i '' 's/app(AppriseApiClient::class)/app(ChannelDispatcher::class)/; s#use App\\Support\\Apprise\\AppriseApiClient;#use App\\Support\\Delivery\\ChannelDispatcher;#' tests/Feature/DeliverWeatherAlertTest.php tests/Feature/DeliverScheduledAlertTest.php`, then confirm no other `AppriseApiClient` references remain in those files). Their assertions (including `error_detail` `'Apprise reported non-delivery.'`) stay untouched. Add a contract test in `ChannelTypesTest.php`:
```php
test('every registered channel type implements the transport contract', function () {
    foreach (['email', 'ntfy', 'discord', 'slack', 'telegram', 'pushover', 'msteams'] as $slug) {
        $type = app(ChannelTypeRegistry::class)->find($slug);

        expect($type->transport())->toBe(App\Enums\ChannelTransport::Apprise)
            ->and($type->supportsInlineCreate())->toBeTrue()
            ->and($type->isAvailable())->toBeTrue()
            ->and($type->buildDestination(['topic' => 'x', 'to' => 'a@b.co', 'webhook_url' => 'https://discord.com/api/webhooks/1/a', 'bot_token' => '1:a', 'chat_id' => '1', 'user_key' => str_repeat('a', 30), 'api_token' => str_repeat('b', 30)]))->toHaveKey('apprise_url')
            ->not->toHaveKey('config');
    }
});
```
(`buildDestination` for Slack/Teams uses their real formats; if this generic data array can't build one of them, build the per-type array in the test explicitly.)

- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.**

`ChannelTransport` enum (three cases as above). `DeliveryResult`:
```php
<?php

namespace App\Support\Delivery;

final class DeliveryResult
{
    public function __construct(public readonly bool $success, public readonly ?string $error = null) {}

    public static function delivered(): self
    {
        return new self(true);
    }

    public static function failed(string $error): self
    {
        return new self(false, $error);
    }
}
```
`Transport` interface as specified. `AppriseTransport`:
```php
<?php

namespace App\Support\Delivery\Transports;

use App\Models\NotificationChannel;
use App\Support\Apprise\AppriseApiClient;
use App\Support\Delivery\DeliveryResult;

class AppriseTransport implements Transport
{
    public function __construct(private readonly AppriseApiClient $client) {}

    public function send(NotificationChannel $channel, string $body, ?string $title, array $meta = []): DeliveryResult
    {
        return $this->client->notify(url: (string) $channel->apprise_url, body: $body, title: $title)
            ? DeliveryResult::delivered()
            : DeliveryResult::failed('Apprise reported non-delivery.');
    }
}
```
`ChannelDispatcher`:
```php
<?php

namespace App\Support\Delivery;

use App\Enums\ChannelTransport;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\Delivery\Transports\AppriseTransport;
use App\Support\Delivery\Transports\Transport;

class ChannelDispatcher
{
    public function __construct(private readonly ChannelTypeRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function send(NotificationChannel $channel, string $body, ?string $title = null, array $meta = []): DeliveryResult
    {
        $type = $this->registry->find($channel->channel_type);

        if ($type === null) {
            return DeliveryResult::failed("Unknown channel type [{$channel->channel_type}].");
        }

        return $this->transportFor($type->transport())->send($channel, $body, $title, $meta);
    }

    private function transportFor(ChannelTransport $transport): Transport
    {
        return match ($transport) {
            ChannelTransport::Apprise => app(AppriseTransport::class),
            // Webhook and WebPush transports are registered by their own tasks.
            default => throw new \LogicException("No transport registered for [{$transport->value}]."),
        };
    }
}
```
Contract + trait (`UsesAppriseTransport`) + the seven types `use UsesAppriseTransport;` and drop nothing else (each keeps `buildAppriseUrl`). Registry unchanged except (Step in Task 5) `isAvailable()` filtering — implement the filter now: `forPicker()` adds `->filter(fn (array $row) => $row['type'] !== null && $row['type']->isAvailable())`.

Migration:
```php
Schema::table('notification_channels', function (Blueprint $table) {
    $table->text('apprise_url')->nullable()->change();
    $table->text('config')->nullable()->after('apprise_url');
});
```
(down: drop `config`; restoring NOT NULL requires no null rows — write `down()` to delete rows with null `apprise_url` first, with a comment.) Model: add `'config'` to `#[Fillable([...])]`, `'config' => 'encrypted:array'` to casts, and extend the activity-log comment to say `config` is excluded for the same reason.

Jobs: `DeliverWeatherAlert::handle(ChannelDispatcher $dispatcher)` and `DeliverScheduledAlert::handle(ChannelDispatcher $dispatcher)`: replace the `$client->notify(...)` + `$delivered` handling with
```php
$result = $dispatcher->send($channel, Str::limit($bodySource, $maxLength, preserveWords: true), app(RenderAlertSubject::class)($rule, $product->product), [
    'type' => 'alert',
    'rule' => $rule->name,
    'product' => $product->product?->name,
]);
```
(`DeliverScheduledAlert`: same call with its `$body`/`$title` and `'type' => 'alert', 'rule' => $rule->name`), then `'status' => $result->success ? 'success' : 'failure', 'error_detail' => $result->error`. Update the imports (remove `AppriseApiClient`, add `ChannelDispatcher`).

`DispatchChannelConfirmationCode`: replace the Apprise call with
```php
return app(ChannelDispatcher::class)->send(
    $channel,
    "Your WxNotify confirmation code is: {$code}\n\nEnter this code in the dashboard to finish connecting this channel. It expires in 15 minutes.",
    'Confirm your WxNotify channel',
    ['type' => 'confirmation', 'code' => $code],
)->success;
```
keeping the existing `try/catch (ConnectionException)`. Creation sites: in `ConnectChannel` step-2 `afterValidation` and in `AlertRuleForm::connectChannelAction`, replace `$type->buildAppriseUrl(...)` with `$destination = $type->buildDestination(...)` and create with `'label' => $destination['label'] ?? $type->label(), ...Arr::only($destination, ['apprise_url', 'config'])` (add `use Illuminate\Support\Arr;`). In `AlertRuleForm::channelsSelect()`, filter the suffix actions: `$registry->forPicker()->filter(fn (array $row): bool => $row['type']->supportsInlineCreate())->map(...)->all()` (add `->values()` before `->map` if needed for the array shape).
- [ ] **Step 4: Run** `ChannelDispatcherTest`, `ChannelTypesTest`, `ChannelConnectionTest`, `DeliverWeatherAlertTest`, `DeliverScheduledAlertTest`, `SendActivityAlertTest`, `NotificationChannelResourceTest`, `AlertRuleResourceTest` → PASS; then the full suite (expect all green; report the count).
- [ ] **Step 5: Mutation-check:** make `AppriseTransport::send()` always return `DeliveryResult::delivered()`; `ChannelDispatcherTest` (the 204 case) and the existing `DeliverWeatherAlertTest` failure-path test must fail. Restore.
- [ ] **Step 6: Commit** `git add app database tests && git commit -m "Route channel delivery through a dispatcher with pluggable transports"`.

### Task 3: SSRF guard for subscriber-controlled URLs

**Files:** Create `app/Support/Delivery/WebhookUrlGuard.php`, `app/Rules/SafeWebhookUrl.php`, `tests/Feature/WebhookUrlGuardTest.php`; modify `config/wxnotify.php`, `.env.example`.

**Interfaces:**
- Produces: `WebhookUrlGuard::__construct(?Closure $resolver = null)` where `$resolver(string $host): array<string>` returns the IPs a host resolves to (default uses `gethostbynamel` + AAAA lookup); `WebhookUrlGuard::violation(string $url): ?string` — a human-readable reason, or `null` when the URL is safe. `App\Rules\SafeWebhookUrl implements ValidationRule` (uses `app(WebhookUrlGuard::class)`); config `wxnotify.webhooks.allow_private_networks` (bool, env `WXNOTIFY_WEBHOOKS_ALLOW_PRIVATE`, default `false`).

- [ ] **Step 1: Failing tests** `tests/Feature/WebhookUrlGuardTest.php` (helper `chanGuard(array $ips)` builds `new WebhookUrlGuard(fn () => $ips)`):
```php
<?php

use App\Rules\SafeWebhookUrl;
use App\Support\Delivery\WebhookUrlGuard;
use Illuminate\Support\Facades\Validator;

function chanGuard(array $ips = ['93.184.216.34']): WebhookUrlGuard
{
    return new WebhookUrlGuard(fn (string $host): array => $ips);
}

test('a public https url on a normal port is allowed', function () {
    expect(chanGuard()->violation('https://hooks.example.com/wx'))->toBeNull()
        ->and(chanGuard()->violation('https://hooks.example.com:8443/wx'))->toBeNull();
});

test('non-https, credentials, bad ports and missing hosts are rejected', function (string $url) {
    expect(chanGuard()->violation($url))->not->toBeNull();
})->with([
    'http' => 'http://hooks.example.com/wx',
    'ftp' => 'ftp://hooks.example.com/wx',
    'userinfo' => 'https://user:pass@hooks.example.com/wx',
    'ssh port' => 'https://hooks.example.com:22/wx',
    'smtp port' => 'https://hooks.example.com:25/wx',
    'no host' => 'https:///wx',
    'not a url' => 'nonsense',
]);

test('private, loopback, link-local, metadata, cgnat and reserved addresses are rejected', function (string $ip) {
    expect(chanGuard([$ip])->violation('https://hooks.example.com/wx'))->not->toBeNull();
})->with(['10.0.0.5', '172.16.4.4', '192.168.1.10', '127.0.0.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '240.0.0.1', '::1', 'fe80::1', 'fc00::1', '::ffff:10.0.0.1', '::ffff:127.0.0.1']);

test('ip literals in the url are checked without dns', function () {
    expect(chanGuard()->violation('https://10.0.0.5/wx'))->not->toBeNull()
        ->and(chanGuard()->violation('https://[::1]/wx'))->not->toBeNull()
        ->and(chanGuard()->violation('https://93.184.216.34/wx'))->toBeNull();
});

test('a hostname that resolves to any private address is rejected even when another address is public', function () {
    expect(chanGuard(['93.184.216.34', '10.0.0.5'])->violation('https://hooks.example.com/wx'))->not->toBeNull();
});

test('an unresolvable host is rejected', function () {
    expect(chanGuard([])->violation('https://nope.example.com/wx'))->not->toBeNull();
});

test('internal hostnames are rejected without resolving', function (string $host) {
    expect(chanGuard()->violation("https://{$host}/wx"))->not->toBeNull();
})->with(['localhost', 'printer.local', 'db.internal', 'metadata.google.internal']);

test('private networks are allowed only for self-hosted instances that opt in', function () {
    config(['wxnotify.webhooks.allow_private_networks' => true, 'wxnotify.mode' => 'cloud']);
    expect(chanGuard(['10.0.0.5'])->violation('https://hooks.example.com/wx'))->not->toBeNull();

    config(['wxnotify.mode' => 'selfhost']);
    expect(chanGuard(['10.0.0.5'])->violation('https://hooks.example.com/wx'))->toBeNull()
        ->and(chanGuard()->violation('http://hooks.example.com/wx'))->not->toBeNull();

    config(['wxnotify.webhooks.allow_private_networks' => false]);
    expect(chanGuard(['10.0.0.5'])->violation('https://hooks.example.com/wx'))->not->toBeNull();
});

test('the validation rule reports the guard message', function () {
    app()->instance(WebhookUrlGuard::class, chanGuard(['10.0.0.5']));

    $validator = Validator::make(['url' => 'https://hooks.example.com/wx'], ['url' => [new SafeWebhookUrl]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('url'))->toContain('private');
});
```
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.** `config/wxnotify.php` add:
```php
    'webhooks' => [
        // Honored only when mode === 'selfhost': lets a self-hosted instance
        // deliver to services on its own private network.
        'allow_private_networks' => env('WXNOTIFY_WEBHOOKS_ALLOW_PRIVATE', false),
    ],
```
(and `WXNOTIFY_WEBHOOKS_ALLOW_PRIVATE=false` in `.env.example`). `WebhookUrlGuard`:
```php
<?php

namespace App\Support\Delivery;

use Closure;

class WebhookUrlGuard
{
    private const BLOCKED_HOST_SUFFIXES = ['.local', '.internal', '.localhost', '.lan', '.home.arpa'];

    private const BLOCKED_HOSTS = ['localhost', 'metadata.google.internal'];

    public function __construct(private readonly ?Closure $resolver = null) {}

    /** A human-readable reason the URL must not be called, or null when it is safe. */
    public function violation(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'Enter a full https:// URL.';
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return 'The URL must start with https://.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The URL must not contain a username or password.';
        }

        $port = $parts['port'] ?? 443;
        if ($port !== 443 && $port < 1024) {
            return 'That port is not allowed; use 443 or a port above 1023.';
        }

        if ($this->privateNetworksAllowed()) {
            return null;
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return 'That host name points at a private network address.';
        }
        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return 'That host name points at a private network address.';
            }
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolve($host);

        if ($ips === []) {
            return 'That host name could not be resolved.';
        }
        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                return 'That address resolves to a private or reserved network, which is not allowed.';
            }
        }

        return null;
    }

    private function privateNetworksAllowed(): bool
    {
        return config('wxnotify.mode') === 'selfhost' && (bool) config('wxnotify.webhooks.allow_private_networks');
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if ($this->resolver !== null) {
            return array_values(($this->resolver)($host));
        }

        $ips = gethostbynamel($host) ?: [];
        foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            $ips[] = $record['ipv6'];
        }

        return $ips;
    }

    private function isPublicIp(string $ip): bool
    {
        // IPv4-mapped IPv6 (::ffff:a.b.c.d): judge the embedded IPv4 address.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // Carrier-grade NAT 100.64.0.0/10 is not covered by PHP's range flags.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);
            if ($long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255')) {
                return false;
            }
        }

        return true;
    }
}
```
`SafeWebhookUrl`:
```php
<?php

namespace App\Rules;

use App\Support\Delivery\WebhookUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeWebhookUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $violation = app(WebhookUrlGuard::class)->violation((string) $value);

        if ($violation !== null) {
            $fail($violation);
        }
    }
}
```
If a case fails because PHP's `FILTER_FLAG_NO_RES_RANGE` treats an address differently (e.g. `fc00::1` is private, `fe80::1` link-local, `::1` loopback), verify with a quick `php -r` and adjust the explicit checks rather than dropping a test case. The test for the message contains `private`: keep that word in the violation for the private-network cases.
- [ ] **Step 4: Run** → PASS; mutation-check (remove the CGNAT block; the `100.64.0.1` case must fail; restore). Pint; commit `git add app config .env.example tests && git commit -m "Add an SSRF guard for subscriber-supplied webhook and push URLs"`.

### Task 4: Custom Webhooks channel

**Files:**
- Create: `app/Support/ChannelTypes/WebhookChannelType.php`, `app/Support/Delivery/Transports/WebhookTransport.php`, `database/migrations/2026_09_20_100003_add_webhook_channel_type.php`, `tests/Feature/WebhookChannelTest.php`
- Modify: `composer.json`/`composer.lock`, `config/webhook-server.php` (published), `app/Support/ChannelTypes/ChannelTypeRegistry.php`, `app/Support/Delivery/ChannelDispatcher.php`, `app/Filament/Dashboard/Pages/ConnectChannel.php`, `app/Filament/Dashboard/Resources/NotificationChannels/Schemas/NotificationChannelForm.php`, `database/seeders/ChannelTypeSeeder.php`, `database/factories/NotificationChannelFactory.php`

**Interfaces:**
- Consumes: `ChannelType` contract, `ChannelDispatcher`, `WebhookUrlGuard`/`SafeWebhookUrl` (Task 3), `NotificationChannel::config`.
- Produces: slug `webhook` (`sort_order` 8), `ChannelTransport::Webhook`. `NotificationChannel::config` for webhooks = `['url' => string, 'secret' => string]`. Factory state `NotificationChannelFactory::webhook(string $url = 'https://hooks.example.com/wx', string $secret = 'test-secret')`.

- [ ] **Step 1: Install and spike.** In the Sail container: `composer require spatie/laravel-webhook-server --no-interaction` (expect 3.11.x) and `php artisan vendor:publish --provider="Spatie\WebhookServer\WebhookServerServiceProvider" --tag="webhook-server-config"` (if the tag differs, run `php artisan vendor:publish --provider=… ` and choose the config). Then answer, from `vendor/spatie/laravel-webhook-server/src/` (`WebhookCall.php`, `CallWebhookJob.php`, `Signer/DefaultSigner.php`, `Events/*`), and confirm with a throwaway experiment in `tests/Feature/TmpWebhookSpikeTest.php` (delete afterwards, do not commit):
  1. What does `WebhookCall::create()->url()->payload()->useSecret()->timeoutInSeconds(10)->maximumTries(1)->throwExceptionOnFailure()->dispatchSync()` do on a 200, on a 500, and on a connection error — does it throw, and can the response/error text be read? (If `throwExceptionOnFailure()` doesn't exist in 3.11, find the equivalent or use the `WebhookCallFailedEvent`/`FinalWebhookCallFailedEvent` listeners registered for the duration of the call.)
  2. How does the job obtain its HTTP client (grep `Client`/`app(`) — the seam a test uses to fake the endpoint (expected: a Guzzle `Client` resolved from the container, so tests bind `new GuzzleHttp\Client(['handler' => HandlerStack::create($mock)])`; `Http::fake()` will NOT intercept it).
  3. What header carries the signature and how is it computed (default header `Signature`, HMAC-SHA256 of the JSON payload with the secret) — record the exact algorithm so the subscriber-facing helper text is accurate.
  4. Does `useSecret()`'s signer require the payload be signed as sent (i.e. is the body exactly `json_encode($payload)`)?
  Record the findings as a short comment block at the top of `WebhookTransport` (three lines max) and adjust Step 3's code where the package differs; the tests' assertions (signature header verifiable with the secret, JSON payload shape, failure → `DeliveryResult::failed`, timeout 10 s, one attempt) do not change.
- [ ] **Step 2: Failing tests** `tests/Feature/WebhookChannelTest.php` (write the Guzzle-fake helper per Step 1 finding 2; sketch shown, adapt to the real seam):
```php
<?php

use App\Enums\ChannelTransport;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\WebhookChannelType;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\WebhookUrlGuard;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/** @return array<int, array{request: Psr\Http\Message\RequestInterface}> */
function chanFakeWebhookEndpoint(array $responses): ArrayObject
{
    $history = new ArrayObject;
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    app()->bind(Client::class, fn () => new Client(['handler' => $stack]));

    return $history;
}

beforeEach(function () {
    // Every hostname resolves to a public address unless a test says otherwise.
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));
});

test('the webhook type is a free-slot, non-inline, webhook-transport type', function () {
    $type = app(ChannelTypeRegistry::class)->find('webhook');

    expect($type)->toBeInstanceOf(WebhookChannelType::class)
        ->and($type->transport())->toBe(ChannelTransport::Webhook)
        ->and($type->countsTowardFreeSlot())->toBeTrue()
        ->and($type->supportsInlineCreate())->toBeFalse();
});

test('building the destination generates a 64-character secret and keeps the url', function () {
    $destination = (new WebhookChannelType)->buildDestination(['url' => 'https://hooks.example.com/wx']);

    expect($destination['config']['url'])->toBe('https://hooks.example.com/wx')
        ->and($destination['config']['secret'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($destination)->not->toHaveKey('apprise_url')
        ->and($destination['label'])->toBe('Webhook (hooks.example.com)');
});

test('the url field rejects http and private targets', function () {
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['10.0.0.5']));
    $field = collect((new WebhookChannelType)->formSchema())->firstWhere(fn ($f) => $f->getName() === 'url');

    foreach (['http://hooks.example.com/wx', 'https://hooks.example.com/wx', 'https://10.0.0.5/wx'] as $url) {
        expect(Validator::make(['url' => $url], ['url' => $field->getValidationRules()])->fails())->toBeTrue();
    }
});

test('delivery posts a signed json payload and reports success', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $channel = NotificationChannel::factory()->webhook('https://hooks.example.com/wx', 's3cret')->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning text', 'Tornado Warning', ['type' => 'alert', 'rule' => 'My rule', 'product' => 'Tornado Warning']);

    expect($result->success)->toBeTrue();
    $request = $history[0]['request'];
    $body = (string) $request->getBody();
    $payload = json_decode($body, true);

    expect((string) $request->getUri())->toBe('https://hooks.example.com/wx')
        ->and($payload)->toMatchArray(['type' => 'alert', 'title' => 'Tornado Warning', 'body' => 'Tornado Warning text', 'rule' => 'My rule', 'product' => 'Tornado Warning'])
        ->and($payload['sent_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/')
        ->and($request->getHeaderLine('Signature'))->toBe(hash_hmac('sha256', $body, 's3cret'));
});

test('a confirmation message carries the code in the payload', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $channel = NotificationChannel::factory()->webhook()->create();

    app(ChannelDispatcher::class)->send($channel, 'Your code', 'Confirm', ['type' => 'confirmation', 'code' => '123456']);

    expect(json_decode((string) $history[0]['request']->getBody(), true))->toMatchArray(['type' => 'confirmation', 'code' => '123456']);
});

test('a non-2xx response is a failure with a useful message and only one attempt is made', function () {
    $history = chanFakeWebhookEndpoint([new Response(500), new Response(200)]);
    $channel = NotificationChannel::factory()->webhook()->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'body', 'title');

    expect($result->success)->toBeFalse()->and($result->error)->toContain('500')
        ->and(count($history))->toBe(1);
});

test('a connection error is a failure, not an exception', function () {
    chanFakeWebhookEndpoint([new GuzzleHttp\Exception\ConnectException('boom', new GuzzleHttp\Psr7\Request('POST', 'https://hooks.example.com/wx'))]);
    $channel = NotificationChannel::factory()->webhook()->create();

    expect(app(ChannelDispatcher::class)->send($channel, 'body')->success)->toBeFalse();
});

test('the url is re-checked at send time and a now-private target is never called', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $channel = NotificationChannel::factory()->webhook()->create();
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['10.0.0.5']));

    $result = app(ChannelDispatcher::class)->send($channel, 'body');

    expect($result->success)->toBeFalse()->and($result->error)->toContain('private')
        ->and(count($history))->toBe(0);
});

test('a webhook channel with a missing config is a failure', function () {
    $channel = NotificationChannel::factory()->webhook()->create(['config' => null]);

    expect(app(ChannelDispatcher::class)->send($channel, 'body')->success)->toBeFalse();
});

test('the signing secret never reaches the activity log or the error message', function () {
    chanFakeWebhookEndpoint([new Response(500, [], 'echoing s3cret')]);
    $channel = NotificationChannel::factory()->webhook('https://hooks.example.com/wx', 's3cret')->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'body');

    expect($result->error)->not->toContain('s3cret')
        ->and(json_encode(Spatie\Activitylog\Models\Activity::all()->toArray()))->not->toContain('s3cret');
});
```
Add wizard and edit-page tests to `tests/Feature/ChannelConnectionTest.php`/`NotificationChannelResourceTest.php` style files (use existing helpers there):
```php
test('the webhook wizard creates a channel with a secret, shows it on the confirm step, and sends the code to the endpoint', function () {
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));
    $history = chanFakeWebhookEndpoint([new Response(200)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'webhook']);
    goToConnectChannelStep($component, 0);
    $component->assertWizardCurrentStep(2)->fillForm(['url' => 'https://hooks.example.com/wx']);
    goToConnectChannelStep($component, 1);
    $component->assertWizardCurrentStep(3);

    $channel = NotificationChannel::where('user_id', $this->user->id)->sole();
    $payload = json_decode((string) $history[0]['request']->getBody(), true);

    expect($channel->channel_type)->toBe('webhook')->and($channel->apprise_url)->toBeNull()
        ->and($payload['type'])->toBe('confirmation')->and($payload['code'])->toBe($channel->confirmation_code);
    $component->assertFormSet(['webhook_secret' => $channel->config['secret']]);
});

test('a webhook whose endpoint rejects the confirmation message is not left behind', function () {
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));
    chanFakeWebhookEndpoint([new Response(500)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'webhook']);
    goToConnectChannelStep($component, 0);
    $component->fillForm(['url' => 'https://hooks.example.com/wx']);
    goToConnectChannelStep($component, 1);

    expect(NotificationChannel::count())->toBe(0);
});
```
(the helper `chanFakeWebhookEndpoint` is defined in `WebhookChannelTest.php`; because Pest helper names are global but load order isn't guaranteed, move that helper and `goToConnectChannelStep` into `tests/Pest.php` if the suite can't find it — check how `tests/Pest.php` is organised first.) Edit page: a test that `EditNotificationChannel`… the resource has only an index page + `EditAction` modal; test through `livewire(ListNotificationChannels::class)->mountTableAction('edit', $channel)->assertFormSet(['config.secret' => 's3cret'])` for a webhook channel and that the secret field is absent (`assertFormFieldHidden`) for a Discord channel.
- [ ] **Step 3: Run** → FAIL.
- [ ] **Step 4: Implement.**
`WebhookChannelType` (implements `ChannelType`, does NOT use `UsesAppriseTransport`):
```php
<?php

namespace App\Support\ChannelTypes;

use App\Enums\ChannelTransport;
use App\Rules\SafeWebhookUrl;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;

class WebhookChannelType implements ChannelType
{
    public function slug(): string
    {
        return 'webhook';
    }

    public function label(): string
    {
        return 'Custom Webhook';
    }

    public function icon(): string
    {
        return 'heroicon-o-arrow-up-right';
    }

    public function formSchema(): array
    {
        return [
            TextInput::make('url')
                ->label('Webhook URL')
                ->url()
                ->required()
                ->rule(new SafeWebhookUrl)
                ->helperText('WxNotify will POST a signed JSON payload to this https:// URL. You will get a signing secret on the next step.'),
        ];
    }

    public function transport(): ChannelTransport
    {
        return ChannelTransport::Webhook;
    }

    public function buildDestination(array $data): array
    {
        return [
            'config' => ['url' => $data['url'], 'secret' => bin2hex(random_bytes(32))],
            'label' => 'Webhook ('.Str::limit((string) parse_url($data['url'], PHP_URL_HOST), 40, '').')',
        ];
    }

    public function countsTowardFreeSlot(): bool
    {
        return true;
    }

    public function supportsInlineCreate(): bool
    {
        return false;
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
```
`WebhookTransport` (adapt the call chain to the spike's findings; keep the structure):
```php
<?php

namespace App\Support\Delivery\Transports;

use App\Models\NotificationChannel;
use App\Support\Delivery\DeliveryResult;
use App\Support\Delivery\WebhookUrlGuard;
use Spatie\WebhookServer\WebhookCall;
use Throwable;

class WebhookTransport implements Transport
{
    public function __construct(private readonly WebhookUrlGuard $guard) {}

    public function send(NotificationChannel $channel, string $body, ?string $title, array $meta = []): DeliveryResult
    {
        $url = $channel->config['url'] ?? null;
        $secret = $channel->config['secret'] ?? null;

        if (! is_string($url) || ! is_string($secret) || $url === '' || $secret === '') {
            return DeliveryResult::failed('This webhook channel is missing its URL or signing secret.');
        }

        // Re-checked on every send: DNS can change after the URL was saved.
        if (($violation = $this->guard->violation($url)) !== null) {
            return DeliveryResult::failed($violation);
        }

        $payload = [
            'type' => $meta['type'] ?? 'alert',
            'title' => $title,
            'body' => $body,
            'sent_at' => now()->utc()->toIso8601String(),
        ] + array_diff_key($meta, ['type' => true]);

        try {
            WebhookCall::create()
                ->url($url)
                ->payload($payload)
                ->useSecret($secret)
                ->timeoutInSeconds(10)
                ->maximumTries(1)
                ->throwExceptionOnFailure()
                ->dispatchSync();
        } catch (Throwable $e) {
            // The exception text can echo the endpoint's response body: never include the secret, keep it short.
            return DeliveryResult::failed(str_replace($secret, '[redacted]', 'Webhook delivery failed: '.Str::limit($e->getMessage(), 200)));
        }

        return DeliveryResult::delivered();
    }
}
```
(add `use Illuminate\Support\Str;`). In `ChannelDispatcher::transportFor()` add `ChannelTransport::Webhook => app(WebhookTransport::class)` and drop the `default`-arm mention for it. Register `WebhookChannelType` in the registry. Migration `2026_09_20_100003_add_webhook_channel_type.php` (same shape as Task 1's, slug `webhook`, `sort_order` 8) and `SHIPPED` row. Factory state:
```php
public function webhook(string $url = 'https://hooks.example.com/wx', string $secret = 'test-secret'): static
{
    return $this->state(fn () => ['channel_type' => 'webhook', 'apprise_url' => null, 'config' => ['url' => $url, 'secret' => $secret]]);
}
```
`ConnectChannel` changes: in step 2's `afterValidation`, after `$destination = $type->buildDestination($get());` (from Task 2) add `if (isset($destination['config']['secret'])) { $set('webhook_secret', $destination['config']['secret']); }`; in the Confirm step add, before the code input:
```php
TextInput::make('webhook_secret')
    ->label('Signing secret')
    ->password()
    ->revealable()
    ->readOnly()
    ->dehydrated(false)
    ->helperText('Verify each request by computing HMAC-SHA256 of the raw request body with this secret and comparing it to the "Signature" header. Copy it now; you can reveal it again from the channel\'s edit form.')
    ->visible(fn (Get $get): bool => filled($get('webhook_secret'))),
```
and update the code input's helper text to `->helperText(fn (Get $get): string => filled($get('webhook_secret')) ? 'We just POSTed a confirmation message to your endpoint; enter the 6-digit code from its "code" field.' : 'We just sent a 6-digit code to the channel you configured.')`. `NotificationChannelForm`: add
```php
TextInput::make('config.secret')
    ->label('Signing secret')
    ->password()
    ->revealable()
    ->readOnly()
    ->dehydrated(false)
    ->visible(fn (?NotificationChannel $record): bool => $record?->channel_type === 'webhook'),
```
(import `App\Models\NotificationChannel`). Confirm the existing `EditAction`/`handleRecordUpdate` path doesn't overwrite `config` (the field is `dehydrated(false)`).
- [ ] **Step 5: Run** `WebhookChannelTest`, `ChannelConnectionTest`, `NotificationChannelResourceTest`, `ChannelTypeSeederTest`, `ChannelDispatcherTest` → PASS. Mutation-checks: remove the send-time guard call (the re-check test must fail); change the signature to use the wrong secret (signature test must fail); restore.
- [ ] **Step 6: End-to-end against a local receiver (manual proof of the real Guzzle/Spatie path).** In the Sail container, start a throwaway receiver that records request headers and body (`mkdir /tmp/hookrecv && printf '<?php file_put_contents("/tmp/hookrecv/log", json_encode(["h" => getallheaders(), "b" => file_get_contents("php://input")]));' > /tmp/hookrecv/index.php && php -S 127.0.0.1:9099 -t /tmp/hookrecv &`). Then in `php artisan tinker --execute` bind a permissive guard for that session only (`app()->instance(App\Support\Delivery\WebhookUrlGuard::class, new class extends App\Support\Delivery\WebhookUrlGuard { public function violation(string $url): ?string { return null; } });`), build an unsaved `NotificationChannel` with `channel_type = 'webhook'` and `config = ['url' => 'http://127.0.0.1:9099/', 'secret' => 's3cret']`, send once through `ChannelDispatcher`, and verify the logged `Signature` header equals `hash_hmac('sha256', <logged body>, 's3cret')` and `result->success` is true. Report the outcome, then kill the receiver and delete `/tmp/hookrecv`. Nothing in `.env` or the dev database changes.
- [ ] **Step 7: Commit** `git add composer.json composer.lock config app database tests && git commit -m "Add the Custom Webhooks channel with signed delivery and SSRF protection"`.

---

## Stage 3 — Web Push

### Task 5: Web Push backend (type, transport, VAPID, catalog)

**Files:**
- Create: `app/Support/ChannelTypes/WebPushChannelType.php`, `app/Support/Delivery/Transports/WebPushTransport.php`, `database/migrations/2026_09_20_100004_add_webpush_channel_type.php`, `tests/Feature/WebPushChannelTest.php`
- Modify: `composer.json`/`composer.lock`, `config/webpush.php` (published), `.env.example`, `app/Support/ChannelTypes/ChannelTypeRegistry.php`, `app/Support/Delivery/ChannelDispatcher.php`, `database/seeders/ChannelTypeSeeder.php`, `database/factories/NotificationChannelFactory.php`

**Interfaces:**
- Consumes: `ChannelType` contract (`isAvailable()` hides the tile when VAPID isn't configured — the registry's `forPicker()` already filters on it), `WebhookUrlGuard` (also guards push endpoints — a subscriber could otherwise submit an internal URL as their "push endpoint").
- Produces: slug `webpush` (`sort_order` 9), `ChannelTransport::WebPush`. `NotificationChannel::config` for Web Push = `['endpoint' => string, 'public_key' => string, 'auth_token' => string, 'content_encoding' => 'aes128gcm']`. `WebPushChannelType::parseSubscription(string $json): ?array` (public static; returns that config array or null). Factory state `webPush()`.

- [ ] **Step 1: Install.** `composer require laravel-notification-channels/webpush --no-interaction` (expect 13.0.x + `minishlink/web-push` 11.x). `php artisan vendor:publish --provider="NotificationChannels\WebPush\WebPushServiceProvider" --tag=config` → `config/webpush.php`; do NOT publish or run the package's `push_subscriptions` migration (our subscriptions live encrypted in `notification_channels.config`); if the package auto-loads its migration (`loadMigrationsFrom`), note that in the report — an unused `push_subscriptions` table is acceptable but must not be created in the dev DB by this task (do not run `migrate`; RefreshDatabase will create it in tests, harmless). Add `VAPID_PUBLIC_KEY=`, `VAPID_PRIVATE_KEY=` and `VAPID_SUBJECT="${APP_URL}"` to `.env.example`. Generate dev keys: `php artisan webpush:vapid --show` (prints keys without writing `.env`); tell the user to add them to their `.env` (do NOT edit `.env`). In `config/webpush.php` confirm the `vapid.public_key/private_key/subject` config keys and use exactly those.
- [ ] **Step 2: Failing tests** `tests/Feature/WebPushChannelTest.php`. The push service is faked by binding a stub for `Minishlink\WebPush\WebPush` (constructed inside the transport via `app(WebPush::class, ['auth' => …])`); use an anonymous `WebPush` subclass whose `sendOneNotification` records the subscription + payload and returns a canned `MessageSentReport`:
```php
<?php

use App\Enums\ChannelTransport;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\WebPushChannelType;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\WebhookUrlGuard;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Message\RequestInterface;

function chanPushSubscriptionJson(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123'): string
{
    return json_encode(['endpoint' => $endpoint, 'keys' => ['p256dh' => str_repeat('B', 87), 'auth' => str_repeat('a', 22)]]);
}

function chanFakePushService(bool $success = true, int $status = 201, bool $expired = false): ArrayObject
{
    $sent = new ArrayObject;
    app()->bind(WebPush::class, fn () => new class($sent, $success, $status, $expired) extends WebPush
    {
        public function __construct(private ArrayObject $sent, private bool $ok, private int $status, private bool $expired)
        {
            parent::__construct(['VAPID' => ['subject' => 'mailto:test@example.com', 'publicKey' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSKw3nJH1RhfDs_CLpGzz0aCkGhKbfnhKs', 'privateKey' => 'UUxI4O8-FbRouAevSmBQ6o18hgE4nSG3qwvJTfKc-ls']]]);
        }

        public function sendOneNotification(Subscription $subscription, ?string $payload = null, array $options = []): MessageSentReport
        {
            $this->sent[] = ['subscription' => $subscription, 'payload' => $payload];
            $request = new GuzzleHttp\Psr7\Request('POST', $subscription->getEndpoint());
            $response = new GuzzleHttp\Psr7\Response($this->expired ? 410 : $this->status);

            return new MessageSentReport($request, $response, $this->ok && ! $this->expired, $this->expired ? 'Gone' : 'ok');
        }
    });

    return $sent;
}

beforeEach(function () {
    config(['webpush.vapid.public_key' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSKw3nJH1RhfDs_CLpGzz0aCkGhKbfnhKs', 'webpush.vapid.private_key' => 'UUxI4O8-FbRouAevSmBQ6o18hgE4nSG3qwvJTfKc-ls', 'webpush.vapid.subject' => 'mailto:test@example.com']);
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));
});

test('the web push type is free-slot, non-inline, webpush-transport, and only available with vapid keys', function () {
    $type = app(ChannelTypeRegistry::class)->find('webpush');

    expect($type)->toBeInstanceOf(WebPushChannelType::class)
        ->and($type->transport())->toBe(ChannelTransport::WebPush)
        ->and($type->countsTowardFreeSlot())->toBeTrue()
        ->and($type->supportsInlineCreate())->toBeFalse()
        ->and($type->isAvailable())->toBeTrue();

    config(['webpush.vapid.public_key' => null]);
    expect($type->isAvailable())->toBeFalse()
        ->and(app(ChannelTypeRegistry::class)->forPicker()->map(fn ($r) => $r['type']->slug())->all())->not->toContain('webpush');
});

test('a browser subscription is parsed into channel config', function () {
    expect(WebPushChannelType::parseSubscription(chanPushSubscriptionJson()))->toBe([
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        'public_key' => str_repeat('B', 87),
        'auth_token' => str_repeat('a', 22),
        'content_encoding' => 'aes128gcm',
    ]);
});

test('malformed subscriptions and unsafe endpoints are rejected', function (string $json) {
    expect(WebPushChannelType::parseSubscription($json))->toBeNull();
})->with([
    'not json' => 'nope',
    'no keys' => '{"endpoint":"https://fcm.googleapis.com/x"}',
    'http endpoint' => '{"endpoint":"http://fcm.googleapis.com/x","keys":{"p256dh":"a","auth":"b"}}',
    'empty' => '',
]);

test('an endpoint resolving to a private network is rejected at save time', function () {
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['10.0.0.5']));

    expect(WebPushChannelType::parseSubscription(chanPushSubscriptionJson('https://push.evil.example/x')))->toBeNull();
});

test('building the destination labels the channel from the browser', function () {
    request()->headers->set('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36');

    $destination = (new WebPushChannelType)->buildDestination(['subscription' => chanPushSubscriptionJson()]);

    expect($destination['label'])->toBe('Web Push (Chrome on macOS)')->and($destination['config']['endpoint'])->toContain('fcm.googleapis.com');
});

test('delivery sends a json payload to the stored subscription', function () {
    $sent = chanFakePushService();
    $channel = NotificationChannel::factory()->webPush()->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning text', 'Tornado Warning', ['type' => 'alert']);

    expect($result->success)->toBeTrue()->and(count($sent))->toBe(1);
    $payload = json_decode($sent[0]['payload'], true);
    expect($payload)->toMatchArray(['title' => 'Tornado Warning', 'body' => 'Tornado Warning text', 'type' => 'alert'])
        ->and($sent[0]['subscription']->getEndpoint())->toBe($channel->config['endpoint']);
});

test('long bodies are truncated to fit a push payload', function () {
    $sent = chanFakePushService();
    $channel = NotificationChannel::factory()->webPush()->create();

    app(ChannelDispatcher::class)->send($channel, str_repeat('word ', 400), 'T');

    expect(strlen(json_decode($sent[0]['payload'], true)['body']))->toBeLessThanOrEqual(500);
});

test('a confirmation push carries the code in the body', function () {
    $sent = chanFakePushService();
    $channel = NotificationChannel::factory()->webPush()->create();

    app(ChannelDispatcher::class)->send($channel, 'Your WxNotify confirmation code is: 123456', 'Confirm', ['type' => 'confirmation', 'code' => '123456']);

    expect(json_decode($sent[0]['payload'], true)['body'])->toContain('123456');
});

test('an expired subscription (410) is a failure and un-verifies the channel', function () {
    chanFakePushService(expired: true);
    $channel = NotificationChannel::factory()->webPush()->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'body', 'title');

    expect($result->success)->toBeFalse()->and($result->error)->toContain('no longer')
        ->and($channel->fresh()->isVerified())->toBeFalse();
});

test('a push service error is a failure with the reason', function () {
    chanFakePushService(success: false, status: 500);
    $channel = NotificationChannel::factory()->webPush()->create();

    expect(app(ChannelDispatcher::class)->send($channel, 'body')->success)->toBeFalse();
});

test('the endpoint is re-checked at send time', function () {
    $sent = chanFakePushService();
    $channel = NotificationChannel::factory()->webPush()->create();
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['10.0.0.5']));

    expect(app(ChannelDispatcher::class)->send($channel, 'body')->success)->toBeFalse()->and(count($sent))->toBe(0);
});

test('a missing vapid configuration fails delivery cleanly', function () {
    config(['webpush.vapid.private_key' => null]);
    $channel = NotificationChannel::factory()->webPush()->create();

    expect(app(ChannelDispatcher::class)->send($channel, 'body')->success)->toBeFalse();
});
```
(The VAPID keys above are the well-known public test pair from the Web Push spec examples; if `minishlink` rejects them, generate a pair with `Minishlink\WebPush\VAPID::createVapidKeys()` once and paste it in.) Add a wizard test to `ChannelConnectionTest.php` (Web Push step 2 sets the hidden `subscription` field directly):
```php
test('the web push wizard creates a channel from a browser subscription and pushes the confirmation code', function () {
    config(['webpush.vapid.public_key' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSKw3nJH1RhfDs_CLpGzz0aCkGhKbfnhKs', 'webpush.vapid.private_key' => 'UUxI4O8-FbRouAevSmBQ6o18hgE4nSG3qwvJTfKc-ls', 'webpush.vapid.subject' => 'mailto:test@example.com']);
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));
    $sent = chanFakePushService();

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'webpush']);
    goToConnectChannelStep($component, 0);
    $component->assertWizardCurrentStep(2)->fillForm(['subscription' => chanPushSubscriptionJson()]);
    goToConnectChannelStep($component, 1);
    $component->assertWizardCurrentStep(3);

    $channel = NotificationChannel::where('user_id', $this->user->id)->sole();
    expect($channel->channel_type)->toBe('webpush')->and($channel->apprise_url)->toBeNull()
        ->and(json_decode($sent[0]['payload'], true)['body'])->toContain($channel->confirmation_code);
});
```
- [ ] **Step 3: Run** → FAIL.
- [ ] **Step 4: Implement.**
`WebPushChannelType`:
```php
<?php

namespace App\Support\ChannelTypes;

use App\Enums\ChannelTransport;
use App\Support\ChannelTypes\Contracts\ChannelType;
use App\Support\Delivery\WebhookUrlGuard;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\ViewField;

class WebPushChannelType implements ChannelType
{
    public function slug(): string
    {
        return 'webpush';
    }

    public function label(): string
    {
        return 'Web Push';
    }

    public function icon(): string
    {
        return 'heroicon-o-computer-desktop';
    }

    public function formSchema(): array
    {
        return [
            Hidden::make('subscription')->required(),
            ViewField::make('web_push_setup')
                ->label('')
                ->view('filament.dashboard.channels.web-push-setup')
                ->dehydrated(false),
        ];
    }

    public function transport(): ChannelTransport
    {
        return ChannelTransport::WebPush;
    }

    /**
     * @return ?array{endpoint: string, public_key: string, auth_token: string, content_encoding: string}
     */
    public static function parseSubscription(string $json): ?array
    {
        $data = json_decode($json, true);

        $endpoint = $data['endpoint'] ?? null;
        $publicKey = $data['keys']['p256dh'] ?? null;
        $authToken = $data['keys']['auth'] ?? null;

        if (! is_string($endpoint) || ! is_string($publicKey) || ! is_string($authToken) || $publicKey === '' || $authToken === '') {
            return null;
        }

        // A subscriber controls this URL, so it gets the same SSRF check as a webhook URL.
        if (app(WebhookUrlGuard::class)->violation($endpoint) !== null) {
            return null;
        }

        return [
            'endpoint' => $endpoint,
            'public_key' => $publicKey,
            'auth_token' => $authToken,
            'content_encoding' => in_array($data['contentEncoding'] ?? null, ['aesgcm', 'aes128gcm'], true) ? $data['contentEncoding'] : 'aes128gcm',
        ];
    }

    public function buildDestination(array $data): array
    {
        $config = self::parseSubscription((string) ($data['subscription'] ?? ''));

        if ($config === null) {
            throw new \InvalidArgumentException('That browser subscription is not valid.');
        }

        return ['config' => $config, 'label' => 'Web Push ('.self::describeBrowser((string) request()->userAgent()).')'];
    }

    public function countsTowardFreeSlot(): bool
    {
        return true;
    }

    public function supportsInlineCreate(): bool
    {
        return false;
    }

    public function isAvailable(): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }

    private static function describeBrowser(string $userAgent): string
    {
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'unknown OS',
        };

        return "{$browser} on {$os}";
    }
}
```
`buildDestination` throwing on an invalid subscription must surface as a validation error in the wizard, not a 500: in `ConnectChannel`'s `afterValidation`, wrap the `buildDestination()` call in `try { … } catch (\InvalidArgumentException $e) { Notification::make()->title($e->getMessage())->danger()->send(); throw new Halt; }`.

`WebPushTransport`:
```php
<?php

namespace App\Support\Delivery\Transports;

use App\Models\NotificationChannel;
use App\Support\Delivery\DeliveryResult;
use App\Support\Delivery\WebhookUrlGuard;
use Illuminate\Support\Str;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushTransport implements Transport
{
    public function __construct(private readonly WebhookUrlGuard $guard) {}

    public function send(NotificationChannel $channel, string $body, ?string $title, array $meta = []): DeliveryResult
    {
        $config = $channel->config ?? [];

        if (blank($config['endpoint'] ?? null) || blank($config['public_key'] ?? null) || blank($config['auth_token'] ?? null)) {
            return DeliveryResult::failed('This Web Push channel is missing its browser subscription.');
        }

        if (blank(config('webpush.vapid.public_key')) || blank(config('webpush.vapid.private_key'))) {
            return DeliveryResult::failed('Web Push is not configured on this server (missing VAPID keys).');
        }

        // Re-checked on every send, same as webhooks.
        if (($violation = $this->guard->violation($config['endpoint'])) !== null) {
            return DeliveryResult::failed($violation);
        }

        $webPush = app(WebPush::class, ['auth' => ['VAPID' => [
            'subject' => config('webpush.vapid.subject'),
            'publicKey' => config('webpush.vapid.public_key'),
            'privateKey' => config('webpush.vapid.private_key'),
        ]]]);

        $report = $webPush->sendOneNotification(
            Subscription::create([
                'endpoint' => $config['endpoint'],
                'publicKey' => $config['public_key'],
                'authToken' => $config['auth_token'],
                'contentEncoding' => $config['content_encoding'] ?? 'aes128gcm',
            ]),
            json_encode([
                'type' => $meta['type'] ?? 'alert',
                'title' => $title ?? 'WxNotify',
                'body' => Str::limit($body, 500, preserveWords: true),
                'url' => $meta['url'] ?? null,
            ]),
            ['TTL' => 3600, 'urgency' => 'high'],
        );

        if ($report->isSuccess()) {
            return DeliveryResult::delivered();
        }

        if ($report->isSubscriptionExpired()) {
            // The browser revoked the subscription: the channel can no longer deliver until re-enabled.
            $channel->forceFill(['verified_at' => null])->save();

            return DeliveryResult::failed('This browser subscription is no longer valid; re-enable Web Push from Notification Channels.');
        }

        return DeliveryResult::failed('Push service rejected the message: '.Str::limit($report->getReason(), 200));
    }
}
```
Wire `ChannelTransport::WebPush => app(WebPushTransport::class)` into `ChannelDispatcher::transportFor()` (the `default` arm can now be removed since all three cases are handled). Register `WebPushChannelType`; migration `2026_09_20_100004_add_webpush_channel_type.php` (`webpush`, `sort_order` 9) and the `SHIPPED` row; factory state:
```php
public function webPush(): static
{
    return $this->state(fn () => ['channel_type' => 'webpush', 'apprise_url' => null, 'config' => [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        'public_key' => str_repeat('B', 87),
        'auth_token' => str_repeat('a', 22),
        'content_encoding' => 'aes128gcm',
    ]]);
}
```
(`chanPushSubscriptionJson` keys are fake but well-formed; the faked `WebPush` never encrypts. If `Subscription::create()` validates key lengths, use a real-shaped 87-char base64url p256dh / 22-char auth — the values above are that shape.) The view referenced by `formSchema()` is created in Task 6; until then create it as an empty placeholder Blade file so this task's wizard test renders: `resources/views/filament/dashboard/channels/web-push-setup.blade.php` containing `<div></div>`.
- [ ] **Step 5: Run** `WebPushChannelTest`, `ChannelConnectionTest`, `ChannelTypeSeederTest`, `ChannelTypesTest` → PASS; full suite green. Mutation-check: remove the endpoint guard in `parseSubscription` (the private-network test must fail); restore. Pint.
- [ ] **Step 6: Commit** `git add composer.json composer.lock config .env.example app database resources tests && git commit -m "Add the Web Push channel backend: type, transport, VAPID gating"`.

### Task 6: Web Push in the browser

**Files:**
- Create: `public/dashboard-sw.js`
- Modify: `resources/views/filament/dashboard/channels/web-push-setup.blade.php`, `tests/Feature/WebPushChannelTest.php`

**Interfaces:**
- Consumes: the wizard step-2 hidden field `data.subscription` (Task 5); `config('webpush.vapid.public_key')`.
- Produces: `/dashboard-sw.js` (service worker: shows `title`/`body`, opens `data.url` or `/dashboard` on click); the Alpine setup component that fills `data.subscription`.

- [ ] **Step 1: Failing tests** (server contract only) appended to `WebPushChannelTest.php`:
```php
test('the service worker handles push and notification click events', function () {
    $js = file_get_contents(public_path('dashboard-sw.js'));

    expect($js)->toContain("addEventListener('push'")
        ->and($js)->toContain("addEventListener('notificationclick'")
        ->and($js)->toContain('showNotification')
        ->and($js)->toContain('openWindow');
});

test('the setup view embeds the vapid public key, the service worker path, and never the private key', function () {
    config(['webpush.vapid.public_key' => 'PUBLICKEY123', 'webpush.vapid.private_key' => 'PRIVATEKEY456']);

    $html = view('filament.dashboard.channels.web-push-setup')->render();

    expect($html)->toContain('PUBLICKEY123')->toContain('/dashboard-sw.js')->not->toContain('PRIVATEKEY456');
});
```
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.** `public/dashboard-sw.js`:
```js
// WxNotify dashboard service worker: shows push notifications sent by the server.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { body: event.data ? event.data.text() : '' };
    }

    event.waitUntil(
        self.registration.showNotification(data.title || 'WxNotify', {
            body: data.body || '',
            icon: '/favicon.ico',
            tag: data.tag || undefined,
            data: { url: data.url || '/dashboard' },
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/dashboard';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            for (const client of windows) {
                if ('focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            return self.clients.openWindow(url);
        })
    );
});
```
`resources/views/filament/dashboard/channels/web-push-setup.blade.php` (an Alpine component; no Vite build needed; inline styles are not required because only Filament components/classes already in the compiled dashboard theme are used):
```blade
<div
    x-data="{
        publicKey: @js(config('webpush.vapid.public_key')),
        supported: ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window),
        status: 'idle',
        error: null,
        urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
            const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const raw = atob(base64);
            return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
        },
        async enable() {
            this.error = null;
            this.status = 'working';
            try {
                const permission = await Notification.requestPermission();
                if (permission !== 'granted') {
                    throw new Error('Notifications are blocked for this site. Allow them in your browser settings and try again.');
                }
                const registration = await navigator.serviceWorker.register('/dashboard-sw.js', { scope: '/dashboard/' });
                await navigator.serviceWorker.ready;
                const existing = await registration.pushManager.getSubscription();
                const subscription = existing ?? await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: this.urlBase64ToUint8Array(this.publicKey),
                });
                await $wire.set('data.subscription', JSON.stringify(subscription.toJSON()));
                this.status = 'ready';
            } catch (e) {
                this.error = e.message || 'Could not enable browser notifications.';
                this.status = 'idle';
            }
        },
    }"
    class="space-y-3"
>
    <template x-if="!supported">
        <p class="text-sm text-danger-600 dark:text-danger-400">
            This browser does not support Web Push. On iPhone and iPad, add WxNotify to your Home Screen first, then open it from there.
        </p>
    </template>

    <template x-if="supported">
        <div class="space-y-3">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Click below and choose "Allow" when your browser asks. Alerts will then appear as system notifications on this device, even when WxNotify isn't open.
            </p>

            <x-filament::button type="button" x-on:click="enable()" x-bind:disabled="status === 'working' || status === 'ready'">
                <span x-show="status === 'idle'">Enable browser notifications</span>
                <span x-show="status === 'working'" x-cloak>Waiting for your browser…</span>
                <span x-show="status === 'ready'" x-cloak>Enabled — click Next</span>
            </x-filament::button>

            <p x-show="error" x-text="error" x-cloak class="text-sm text-danger-600 dark:text-danger-400"></p>
        </div>
    </template>
</div>
```
Because the hidden `subscription` field is `->required()`, the wizard's Next button already refuses to advance until the browser has registered.
- [ ] **Step 4: Run** the WebPush tests → PASS. Pint. Commit `git add public resources tests && git commit -m "Add the Web Push service worker and browser subscribe component"`.
- [ ] **Step 5: Manual browser verification (needs a person).** Ask the user to: add the VAPID keys printed by `php artisan webpush:vapid --show` to `.env`, restart the app container, open `http://localhost/dashboard` (localhost is a secure context) in Chrome, go to Notification Channels → New Channel → Web Push, click "Enable browser notifications", allow the prompt, click Next, confirm that a system notification with the 6-digit code appears, type it in, then trigger a test alert (or `php artisan tinker --execute` calling `app(App\Support\Delivery\ChannelDispatcher::class)->send(App\Models\NotificationChannel::where('channel_type','webpush')->latest()->first(), 'Test alert body', 'Test alert')`) and confirm a notification appears with the app closed. Also check Firefox if available. Report what was and wasn't verified; do not mark this step done without the user's confirmation.

---

## Task 7: Final verification

- [ ] **Step 1:** Record a rule with the Boost `record-rule` tool: glob `app/Support/Delivery/**`, title "Channel delivery goes through ChannelDispatcher; subscriber-controlled URLs go through WebhookUrlGuard", note: "Every send to a subscriber NotificationChannel must go through ChannelDispatcher (SendActivityAlert is the one exception: it sends to an admin-set apprise_target). Any URL a subscriber controls — webhook URLs and Web Push endpoints — must pass WebhookUrlGuard both when saved and again at send time (DNS can change). apprise_url and config are encrypted and must never be logged or echoed in an error message. New channel types need a channel_types migration AND a ChannelTypeSeeder::SHIPPED entry, in matching sort order."
- [ ] **Step 2:** Full suite in the Sail container (`vendor/bin/pest --compact`) → all pass; `vendor/bin/pint --dirty --format agent`; `git status` clean apart from the unrelated `IngestedProductsTable.php` edit.
- [ ] **Step 3:** Apply the new migrations to the DEV database with `docker exec wxnotify-nextgen-laravel.test-1 php artisan migrate --no-interaction` (additive only; NEVER `migrate:fresh`), then open the dashboard's New Channel wizard (Browser tools) as a Plus user and confirm the picker now shows Pushover, Microsoft Teams, Custom Webhook (and Web Push once VAPID keys are set), with the correct locked/free-slot behavior for a Free user. Report what you saw.
- [ ] **Step 4:** Commit any fixes and report.

---

## Self-Review

**Spec coverage:** Pushover + Teams incl. Workflows URL parsing and legacy-URL rejection (Task 1) · dispatcher + transports, nullable `apprise_url` + encrypted `config`, callers moved, retry semantics preserved, `SendActivityAlert` deliberately excluded with the reason (Task 2) · SSRF guard incl. selfhost flag and save/send-time checks (Task 3) · Custom Webhooks with Spatie, HMAC signature, generated secret shown at setup and revealable, confirmation code payload, spike for the async-result question, https-only (Task 4) · Web Push type/transport/VAPID gating/410 handling/endpoint SSRF check/code-by-push verification (Task 5) · service worker + subscribe button + manual browser verification (Task 6) · catalog migrations + `SHIPPED` for every type, free-slot behavior via `countsTowardFreeSlot` (Tasks 1, 4, 5) · testing per stage (each task) · rollout order (stage headings) · rules note (Task 7). Deviations from the spec, all recorded here: `SendActivityAlert` unchanged; webhook channels are also excluded from inline "add a channel" in the alert-rule form (the signing secret needs the wizard's confirm step); Web Push sends through `minishlink/web-push` directly (the package's own notifiable-based sending can't target one stored subscription), so `laravel-notification-channels/webpush` is used for its VAPID tooling/config only; the contract's `buildAppriseUrl()` moves off the interface (replaced by `buildDestination()`) with the method kept on Apprise types.

**Placeholder scan:** the only open items are the flagged spike outcomes (Task 4 Step 1: exact Spatie call chain/test seam; Task 5 Step 1: whether the channels package auto-loads its migration) — each has explicit questions, a fallback, and unchanged test assertions. Task 4 Step 6's local-receiver check is described procedurally because it is a manual verification.

**Type consistency:** `DeliveryResult::delivered()/failed()`, `Transport::send($channel, $body, $title, $meta)`, `ChannelDispatcher::send(…, array $meta = [])`, `buildDestination(): array{apprise_url?, config?, label?}`, `supportsInlineCreate()`, `isAvailable()`, `ChannelTransport::{Apprise,Webhook,WebPush}`, config keys (`url`/`secret`; `endpoint`/`public_key`/`auth_token`/`content_encoding`), factory states `webhook()`/`webPush()`, slugs `pushover`/`msteams`/`webhook`/`webpush` with `sort_order` 6–9 are used identically across tasks and tests.
