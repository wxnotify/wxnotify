# Email Branding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every email the system sends shows the logo and app name in a shared branded header, and Email-channel messages (alerts, confirmation codes, test sends) are sent as Laravel Markdown Mailables with the body in an escaped pre-formatted block.

**Architecture:** Publish Laravel's mail views and customize only the header, so every Markdown-rendered email (mailables and `MailMessage` notifications, including Filament's verification/reset emails) inherits it. Add a `ChannelTransport::Mail` + `MailTransport` that sends a new `ChannelMessageMail` mailable; `EmailChannelType` becomes a config-based destination (`config['to']`) on that transport, and a data migration moves existing Email channels off `mailto://` Apprise URLs.

**Tech Stack:** Laravel 13 (Markdown mail, `Mail::fake()`), Filament v5 notifications, Pest, PostgreSQL.

**Spec:** `docs/superpowers/specs/2026-09-28-email-branding-design.md`

## Global Constraints

- Run PHP/tests inside Sail: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact <path>"`. Pint: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"`. Run Pint before every commit. `phpunit.xml` sets `MAIL_MAILER=array` and `QUEUE_CONNECTION=sync`.
- NEVER run `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:seed`, and do NOT run `php artisan migrate` on the dev DB (tests use `RefreshDatabase`). The user runs the migration.
- No new Composer or npm dependencies. Do not edit `CLAUDE.md` (if a hook rewrites it, `git checkout CLAUDE.md`). Do not push.
- Commits end with the trailer `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`. Work on `main`; stage only the files each task names, never `git add -A`.
- Read `.ai/rules/index.md` and the rule files matching the paths you edit (`app/Support/Delivery/**` → delivery.md, `app/Support/**` → support.md, `app/Filament/**` → filament.md, `app/Models/**` → models.md, `tests/Feature/**` → feature.md). Use `search-docs` (Laravel Boost) for Laravel/Filament API questions.
- Test-file top-level function names are global across the Pest suite: prefix every helper you declare (`mailBranding…`, `emailMigration…`) and grep `tests/` for collisions.
- Logo: an absolute URL built from `APP_URL` (`rtrim(config('app.url'), '/').'/images/logo.png'`), NOT embedded; the file is `public/images/logo.png`. App name: `config('app.name')` (`APP_NAME`). In dev `APP_URL` may not match the served port, so the logo may not load in Mailpit — expected, not a defect.
- Everything sent through an Email channel (alerts, confirmation codes, test sends) uses `ChannelMessageMail` with the body in an escaped `<pre>`; other channel types stay on Apprise; `ActivityAlertRule::apprise_target` is untouched.
- Per `.ai/rules/delivery.md`: a `DeliveryResult` error, log line or failure output must never contain an exception message, the recipient address, or SMTP details — fixed strings only. `notification_channels.config` and `apprise_url` are encrypted and must not be logged.
- From address/name are Laravel's `mail.from`. Remove `services.apprise.from_email` (`APPRISE_FROM_EMAIL`) — `EmailChannelType` was its only reader.

---

### Task 1: Branded mail theme header

**Files:**
- Create (via `php artisan vendor:publish --tag=laravel-mail --no-interaction`): `resources/views/vendor/mail/**` (html + text views and `html/themes/default.css`) — commit the published files
- Modify: `resources/views/vendor/mail/html/header.blade.php`
- Test: `tests/Feature/MailBrandingTest.php`

**Interfaces:**
- Produces: every Markdown-rendered email's HTML header contains an `<img>` whose `src` is `{APP_URL}/images/logo.png` and the app name; nothing else in the theme changes.

- [ ] **Step 1: Write the failing test** — `tests/Feature/MailBrandingTest.php`:

```php
<?php

use App\Models\User;
use Filament\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

function mailBrandingLogoUrl(): string
{
    return rtrim((string) config('app.url'), '/').'/images/logo.png';
}

test('a markdown notification email shows the logo and the app name in its header', function () {
    config(['app.name' => 'WxNotify Test', 'app.url' => 'https://alerts.example.test']);

    $html = (string) (new MailMessage)->subject('Hello')->line('Body line')->render();

    expect($html)->toContain('src="https://alerts.example.test/images/logo.png"')
        ->and($html)->toContain('WxNotify Test')
        ->and($html)->toContain('Body line');
});

test('the logo url follows APP_URL and does not depend on the request host', function () {
    config(['app.url' => 'https://alerts.example.test/']);

    $html = (string) (new MailMessage)->line('x')->render();

    expect($html)->toContain('src="https://alerts.example.test/images/logo.png"');
});

test('filament email verification inherits the branded header', function () {
    config(['app.name' => 'WxNotify Test', 'app.url' => 'https://alerts.example.test']);
    $user = User::factory()->create();
    $notification = new VerifyEmail;
    $notification->url = 'https://alerts.example.test/verify/abc';

    $html = (string) $notification->toMail($user)->render();

    expect($html)->toContain('src="https://alerts.example.test/images/logo.png"')
        ->and($html)->toContain('WxNotify Test')
        ->and($html)->toContain('https://alerts.example.test/verify/abc');
});

test('the logo file the header points at exists', function () {
    expect(file_exists(public_path('images/logo.png')))->toBeTrue();
});
```

- [ ] **Step 2: Run to verify it fails** — `vendor/bin/pest --compact tests/Feature/MailBrandingTest.php`. Expected: the header tests FAIL (the default header shows only the app name text and no `images/logo.png`).

- [ ] **Step 3: Implement.** Publish the views: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan vendor:publish --tag=laravel-mail --no-interaction"`. Confirm `resources/views/vendor/mail/html/header.blade.php` now exists, then replace its contents with:

```blade
@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<img src="{{ rtrim((string) config('app.url'), '/').'/images/logo.png' }}" alt="{{ config('app.name') }}" height="40" style="height: 40px; width: auto; vertical-align: middle; border: 0;">
<span style="display: inline-block; vertical-align: middle; margin-left: 10px; font-size: 19px; font-weight: bold; color: #3d4852;">{{ config('app.name') }}</span>
</a>
</td>
</tr>
```

Leave `text/header.blade.php` and every other published file exactly as published (the default text header already prints the app name). If the published header in this Laravel version has a different shape than the default shown in `vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/header.blade.php`, keep its `@props`/structure and change only the logo/app-name content.

- [ ] **Step 4: Run to verify it passes** — `vendor/bin/pest --compact tests/Feature/MailBrandingTest.php` plus every existing test that renders mail (`vendor/bin/pest --compact tests/Feature/RegisterTest.php tests/Feature/CreateUserTest.php`). Expected: all pass.

- [ ] **Step 5: Check in the browser (optional but do it if the tools work).** Trigger a real email in dev and look at it in Mailpit (`http://localhost:8025` or the port from `compose.yaml`) — e.g. register a new subscriber at `/dashboard/register` to send the verification email (no credentials are typed for an existing user; use a throwaway address). The logo may not load if `APP_URL` does not match the served port; the app name and layout must be right. Report what you saw, or say plainly if unavailable.

- [ ] **Step 6: Commit** (Pint first):

```bash
git add resources/views/vendor/mail tests/Feature/MailBrandingTest.php
git commit -m "Brand the shared mail theme header with the logo and app name

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 2: `ChannelMessageMail`, `MailTransport` and the Mail transport case

**Files:**
- Create: `app/Mail/ChannelMessageMail.php` (`php artisan make:mail ChannelMessageMail --markdown=mail.channel-message --no-interaction`, then fill)
- Create: `resources/views/mail/channel-message.blade.php`, `resources/views/mail/channel-message-text.blade.php`
- Create: `app/Support/Delivery/Transports/MailTransport.php`
- Modify: `app/Enums/ChannelTransport.php`, `app/Support/Delivery/ChannelDispatcher.php`, `database/factories/NotificationChannelFactory.php`
- Test: `tests/Feature/ChannelMessageMailTest.php`, `tests/Feature/MailTransportTest.php`

**Interfaces:**
- Consumes: the Task 1 header (mailable HTML inherits it); `Transport::send(NotificationChannel $channel, string $body, ?string $title, array $meta = []): DeliveryResult`; `DeliveryResult::delivered()` / `::failed(string)`.
- Produces: `App\Enums\ChannelTransport::Mail = 'mail'`; `App\Mail\ChannelMessageMail` with `__construct(public readonly ?string $messageTitle, public readonly string $messageBody)`; `App\Support\Delivery\Transports\MailTransport` (`send()` reads `$channel->config['to']`); `ChannelDispatcher::transportFor(ChannelTransport::Mail)` returns `MailTransport`; factory state `NotificationChannel::factory()->email(string $to = 'subscriber@example.com')` (channel_type `email`, `apprise_url` null, `config` `['to' => $to]`).

- [ ] **Step 1: Write the failing tests.** `tests/Feature/ChannelMessageMailTest.php`:

```php
<?php

use App\Mail\ChannelMessageMail;

test('the subject is the title, falling back to the app name', function () {
    config(['app.name' => 'WxNotify Test']);

    expect((new ChannelMessageMail('Tornado Warning', 'body'))->envelope()->subject)->toBe('Tornado Warning')
        ->and((new ChannelMessageMail(null, 'body'))->envelope()->subject)->toBe('WxNotify Test')
        ->and((new ChannelMessageMail('', 'body'))->envelope()->subject)->toBe('WxNotify Test');
});

test('the body is rendered inside an escaped pre-formatted block under the branded header', function () {
    config(['app.name' => 'WxNotify Test', 'app.url' => 'https://alerts.example.test']);

    $html = (new ChannelMessageMail('Title', "TORNADO WARNING\n  indented line <script>alert(1)</script> & more"))->render();

    expect($html)->toContain('<pre')
        ->and($html)->toContain('white-space: pre-wrap')
        ->and($html)->toContain('TORNADO WARNING')
        ->and($html)->toContain('  indented line')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt; &amp; more')
        ->and($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('src="https://alerts.example.test/images/logo.png"');
});

test('blank lines and leading spaces in the body survive markdown processing', function () {
    $html = (new ChannelMessageMail('Title', "LINE ONE\n\n    four-space indented line\n\nLINE FOUR"))->render();

    expect($html)->toContain("LINE ONE\n\n    four-space indented line\n\nLINE FOUR");
});

test('the plain text alternative carries the app name and the body without html', function () {
    config(['app.name' => 'WxNotify Test']);
    $mailable = new ChannelMessageMail('Title', "TORNADO WARNING\n<b>literal</b> & more");

    $text = (string) $mailable->renderText();

    expect($text)->toContain('WxNotify Test')
        ->and($text)->toContain("TORNADO WARNING\n<b>literal</b> & more")
        ->and($text)->not->toContain('<pre');
});
```

(`Mailable::render()` returns the HTML string; if the installed version exposes the text part differently, use the framework's documented way to get the rendered plain-text part — e.g. `$mailable->assertSeeInText()`/the `Mail::fake()` sent-mailable helpers — and keep each test's intent. Confirm with `search-docs`.)

`tests/Feature/MailTransportTest.php`:

```php
<?php

use App\Mail\ChannelMessageMail;
use App\Models\NotificationChannel;
use App\Support\Delivery\Transports\MailTransport;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

test('it sends one branded message to the channel address and reports delivered', function () {
    Mail::fake();
    $channel = NotificationChannel::factory()->email('subscriber@example.com')->create();

    $result = app(MailTransport::class)->send($channel, "body\ntext", 'Test title');

    expect($result->success)->toBeTrue()->and($result->error)->toBeNull();
    Mail::assertSentCount(1);
    Mail::assertSent(ChannelMessageMail::class, fn (ChannelMessageMail $mail) => $mail->hasTo('subscriber@example.com')
        && $mail->messageTitle === 'Test title'
        && $mail->messageBody === "body\ntext");
});

test('a channel without an address fails with a fixed message and sends nothing', function () {
    Mail::fake();
    $channel = NotificationChannel::factory()->email()->create(['config' => []]);

    $result = app(MailTransport::class)->send($channel, 'body', 'title');

    expect($result->success)->toBeFalse()->and($result->error)->toBe('This email channel is missing its address.');
    Mail::assertNothingSent();
});

test('a mailer exception becomes a fixed failure with no exception text or address in it', function () {
    $channel = NotificationChannel::factory()->email('secret-recipient@example.com')->create();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp.internal.example: 535 auth failed for secret-recipient@example.com'));

    $result = app(MailTransport::class)->send($channel, 'body', 'title');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('The mail server could not send the message.')
        ->and($result->error)->not->toContain('smtp.internal')
        ->and($result->error)->not->toContain('secret-recipient');
});
```

(Remove the unused `Mailer` import if Pint flags it. `Mail::shouldReceive` swaps the facade root for a mock in that test only.)

- [ ] **Step 2: Run to verify they fail** — `vendor/bin/pest --compact tests/Feature/ChannelMessageMailTest.php tests/Feature/MailTransportTest.php`. Expected: FAIL (classes/enum case/factory state missing).

- [ ] **Step 3: Implement.** `ChannelTransport` — add the case:

```php
    case Mail = 'mail';
```

`app/Mail/ChannelMessageMail.php`:

```php
<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ChannelMessageMail extends Mailable
{
    public function __construct(
        public readonly ?string $messageTitle,
        public readonly string $messageBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: filled($this->messageTitle) ? $this->messageTitle : (string) config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.channel-message',
            text: 'mail.channel-message-text',
        );
    }
}
```

`resources/views/mail/channel-message.blade.php` (no indentation — indented lines become code blocks in Markdown mail; the `<pre>` is a raw HTML block so its content is passed through untouched, and `{{ }}` escapes the body first):

```blade
<x-mail::message>
<pre style="white-space: pre-wrap; word-wrap: break-word; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px; line-height: 1.5; margin: 0;">{{ $messageBody }}</pre>
</x-mail::message>
```

`resources/views/mail/channel-message-text.blade.php` (a plain-text part; `{!! !!}` is correct here because text/plain has no markup to inject into):

```blade
{{ config('app.name') }}

{!! $messageBody !!}
```

`app/Support/Delivery/Transports/MailTransport.php`:

```php
<?php

namespace App\Support\Delivery\Transports;

use App\Mail\ChannelMessageMail;
use App\Models\NotificationChannel;
use App\Support\Delivery\DeliveryResult;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends through Laravel's configured mailer. Failure text is fixed: an exception
 * message can carry the SMTP host, credentials or the recipient address.
 */
class MailTransport implements Transport
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function send(NotificationChannel $channel, string $body, ?string $title, array $meta = []): DeliveryResult
    {
        $to = $channel->config['to'] ?? null;

        if (blank($to)) {
            return DeliveryResult::failed('This email channel is missing its address.');
        }

        try {
            Mail::to($to)->send(new ChannelMessageMail($title, $body));
        } catch (Throwable) {
            return DeliveryResult::failed('The mail server could not send the message.');
        }

        return DeliveryResult::delivered();
    }
}
```

In `ChannelDispatcher` add `use App\Support\Delivery\Transports\MailTransport;` and the match arm `ChannelTransport::Mail => app(MailTransport::class),`. In `NotificationChannelFactory` add:

```php
    public function email(string $to = 'subscriber@example.com'): static
    {
        return $this->state(fn () => ['channel_type' => 'email', 'apprise_url' => null, 'config' => ['to' => $to]]);
    }
```

If a test fails because the Markdown parser changed the `<pre>` body (collapsed 3+ newlines, altered indentation), adjust the VIEW (not the assertions) so whitespace is preserved exactly, and record what you found in your report.

- [ ] **Step 4: Run to verify they pass** — the two new files, plus `tests/Feature/MailBrandingTest.php` and `tests/Feature/ChannelDispatcherTest.php` (unchanged, still green). Expected: all pass.

- [ ] **Step 5: Commit** (Pint first; stage the mailable, both views, the transport, the enum, the dispatcher, the factory and the two test files; message "Add ChannelMessageMail and the Mail channel transport" with the trailer).

---

### Task 3: Email channel on the Mail transport, data migration and cleanup

**Files:**
- Modify: `app/Support/ChannelTypes/EmailChannelType.php`, `config/services.php`, `.env.example`
- Create: `database/migrations/2026_09_28_100001_move_email_channels_to_mail_transport.php`
- Modify (existing tests): `tests/Feature/ChannelTypesTest.php`
- Test: `tests/Feature/EmailChannelMailTest.php`, `tests/Feature/EmailChannelMigrationTest.php`, and one added test each in `tests/Feature/DeliverWeatherAlertTest.php` and `tests/Feature/ChannelConnectionTest.php`
- Modify: `.ai/rules/delivery.md` (via the `record-rule` tool)

**Interfaces:**
- Consumes: `ChannelTransport::Mail`, `MailTransport`, `ChannelMessageMail`, factory `->email()` (Task 2); `ChannelDispatcher`, `DispatchChannelConfirmationCode`, `SendTestNotification`, `DeliverWeatherAlert` (existing).
- Produces: `EmailChannelType::transport() === ChannelTransport::Mail`; `buildDestination(['to' => $addr])` returns `['config' => ['to' => $addr]]`; existing email channels migrated to `config['to']` with `apprise_url` null.

- [ ] **Step 1: Update the existing tests and write the new ones.**

In `tests/Feature/ChannelTypesTest.php`: delete the test `email builds a mailto url from mail config` and replace it with:

```php
test('email keeps its address in config and sends through the mail transport', function () {
    $type = new EmailChannelType;

    expect($type->transport())->toBe(ChannelTransport::Mail)
        ->and($type->buildDestination(['to' => 'subscriber@example.com']))->toBe(['config' => ['to' => 'subscriber@example.com']])
        ->and($type->supportsInlineCreate())->toBeTrue()
        ->and($type->isAvailable())->toBeTrue()
        ->and($type->countsTowardFreeSlot())->toBeFalse();
});
```

and in `every registered channel type implements the transport contract` change the slug list to `['ntfy', 'discord', 'slack', 'telegram', 'pushover', 'msteams']` (email is asserted above; the Apprise loop must no longer include it).

`tests/Feature/EmailChannelMailTest.php`:

```php
<?php

use App\Actions\Channels\DispatchChannelConfirmationCode;
use App\Actions\Channels\SendTestNotification;
use App\Mail\ChannelMessageMail;
use App\Models\NotificationChannel;
use App\Support\Delivery\ChannelDispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

test('the dispatcher sends an email channel through mail and never through apprise', function () {
    Mail::fake();
    Http::fake();
    $channel = NotificationChannel::factory()->email('subscriber@example.com')->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'alert body', 'Alert title');

    expect($result->success)->toBeTrue();
    Mail::assertSent(ChannelMessageMail::class, fn (ChannelMessageMail $mail) => $mail->hasTo('subscriber@example.com') && $mail->messageBody === 'alert body');
    Http::assertNothingSent();
});

test('a confirmation code for an email channel is mailed, not sent through apprise', function () {
    Mail::fake();
    Http::fake();
    $channel = NotificationChannel::factory()->email('subscriber@example.com')->unverified()->create();

    $sent = app(DispatchChannelConfirmationCode::class)($channel);

    $code = $channel->fresh()->confirmation_code;
    expect($sent)->toBeTrue()->and($code)->not->toBeNull();
    Mail::assertSent(ChannelMessageMail::class, fn (ChannelMessageMail $mail) => $mail->hasTo('subscriber@example.com') && str_contains($mail->messageBody, $code));
    Http::assertNothingSent();
});

test('send test for an email channel is mailed', function () {
    Mail::fake();
    Http::fake();
    $channel = NotificationChannel::factory()->email('subscriber@example.com')->create(['label' => 'My inbox']);

    expect(app(SendTestNotification::class)($channel))->toBeTrue();

    Mail::assertSent(ChannelMessageMail::class, fn (ChannelMessageMail $mail) => $mail->hasTo('subscriber@example.com') && str_contains($mail->messageBody, 'My inbox'));
    Http::assertNothingSent();
});

test('another channel type still goes through apprise and sends no mail', function () {
    Mail::fake();
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->create(['channel_type' => 'discord', 'apprise_url' => 'discord://1/abc/']);

    app(ChannelDispatcher::class)->send($channel, 'body', 'title');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/notify/'));
    Mail::assertNothingSent();
});
```

In `tests/Feature/DeliverWeatherAlertTest.php` append (imports `Illuminate\Support\Facades\Mail` and `App\Mail\ChannelMessageMail` if missing; setup mirrors the file's first test):

```php
test('an email channel alert is mailed with the product text and writes a success DeliveryLog row', function () {
    Mail::fake();
    Http::fake();
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $channel = NotificationChannel::factory()->for($rule->user)->email('subscriber@example.com')->create();
    $product = IngestedProduct::factory()->create(['text' => 'Tornado Warning issued for...']);

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(ChannelDispatcher::class));

    Mail::assertSent(ChannelMessageMail::class, fn (ChannelMessageMail $mail) => $mail->hasTo('subscriber@example.com'));
    Http::assertNothingSent();
    expect(DeliveryLog::sole()->status)->toBe('success');
});
```

In `tests/Feature/ChannelConnectionTest.php` add one test that connects an Email channel through the same page/flow the file's existing Apprise-type connect tests use (read its first ~60 lines and copy that setup; the wizard's email type takes a `to` field), then asserts: the created `NotificationChannel` has `channel_type = 'email'`, `config` equal to `['to' => '<the address>']` and `apprise_url` null; and a confirmation email was mailed (`Mail::fake()` + `Mail::assertSent(ChannelMessageMail::class, …)`), with `Http::assertNothingSent()`.

`tests/Feature/EmailChannelMigrationTest.php`:

```php
<?php

use App\Models\NotificationChannel;
use Illuminate\Database\Migrations\Migration;

function emailMigrationUnderTest(): Migration
{
    return require database_path('migrations/2026_09_28_100001_move_email_channels_to_mail_transport.php');
}

test('existing mailto email channels get their address in config and lose the apprise url', function () {
    $verifiedAt = now()->subDay()->startOfSecond();
    $channel = NotificationChannel::factory()->create([
        'channel_type' => 'email',
        'apprise_url' => 'mailto://mailpit:1025/?to=subscriber%40example.com&from=alerts%40wxnotify.test&mode=insecure',
        'verified_at' => $verifiedAt,
    ]);

    emailMigrationUnderTest()->up();

    $fresh = $channel->fresh();
    expect($fresh->config)->toBe(['to' => 'subscriber@example.com'])
        ->and($fresh->apprise_url)->toBeNull()
        ->and($fresh->verified_at->equalTo($verifiedAt))->toBeTrue();
});

test('other channel types and unparsable email rows are left untouched', function () {
    $discord = NotificationChannel::factory()->create(['channel_type' => 'discord', 'apprise_url' => 'discord://1/abc/']);
    $unparsable = NotificationChannel::factory()->create(['channel_type' => 'email', 'apprise_url' => 'mailto://mailpit:1025/?mode=insecure']);

    emailMigrationUnderTest()->up();

    expect($discord->fresh()->apprise_url)->toBe('discord://1/abc/')
        ->and($discord->fresh()->config)->toBeNull()
        ->and($unparsable->fresh()->apprise_url)->toBe('mailto://mailpit:1025/?mode=insecure')
        ->and($unparsable->fresh()->config)->toBeNull();
});

test('running the migration twice does not change an already migrated channel', function () {
    $channel = NotificationChannel::factory()->email('subscriber@example.com')->create();

    emailMigrationUnderTest()->up();

    expect($channel->fresh()->config)->toBe(['to' => 'subscriber@example.com'])
        ->and($channel->fresh()->apprise_url)->toBeNull();
});

test('rolling back rebuilds a working mailto url from the address and the mail config', function () {
    config([
        'mail.mailers.smtp.host' => 'mailpit', 'mail.mailers.smtp.port' => 1025,
        'mail.mailers.smtp.username' => null, 'mail.mailers.smtp.password' => null,
        'mail.mailers.smtp.encryption' => null, 'mail.from.address' => 'alerts@wxnotify.test',
    ]);
    $channel = NotificationChannel::factory()->email('subscriber@example.com')->create();
    $discord = NotificationChannel::factory()->create(['channel_type' => 'discord', 'apprise_url' => 'discord://1/abc/']);

    emailMigrationUnderTest()->down();

    expect($channel->fresh()->apprise_url)->toBe('mailto://mailpit:1025/?to=subscriber%40example.com&from=alerts%40wxnotify.test&mode=insecure')
        ->and($channel->fresh()->config)->toBeNull()
        ->and($discord->fresh()->apprise_url)->toBe('discord://1/abc/');
});
```

- [ ] **Step 2: Run to verify they fail** — `vendor/bin/pest --compact tests/Feature/ChannelTypesTest.php tests/Feature/EmailChannelMailTest.php tests/Feature/EmailChannelMigrationTest.php tests/Feature/DeliverWeatherAlertTest.php tests/Feature/ChannelConnectionTest.php`. Expected: the new/changed tests FAIL (email still Apprise; migration file missing).

- [ ] **Step 3: Implement.** Replace `EmailChannelType` with:

```php
<?php

namespace App\Support\ChannelTypes;

use App\Enums\ChannelTransport;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Filament\Forms\Components\TextInput;

class EmailChannelType implements ChannelType
{
    public function slug(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'Email';
    }

    public function icon(): string
    {
        return 'heroicon-o-envelope';
    }

    public function formSchema(): array
    {
        return [
            TextInput::make('to')
                ->label('Email address')
                ->email()
                ->required()
                ->default(fn () => auth()->user()->email),
        ];
    }

    public function transport(): ChannelTransport
    {
        return ChannelTransport::Mail;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{config: array{to: string}}
     */
    public function buildDestination(array $data): array
    {
        return ['config' => ['to' => (string) $data['to']]];
    }

    public function supportsInlineCreate(): bool
    {
        return true;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function countsTowardFreeSlot(): bool
    {
        return false;
    }
}
```

Remove the `'from_email' => env('APPRISE_FROM_EMAIL', 'alerts@wxnotify.test'),` line from `config/services.php` and the `APPRISE_FROM_EMAIL=alerts@wxnotify.test` line from `.env.example` (grep the repo again for any remaining reader first; the old mailto test that used it is gone).

The data migration `database/migrations/2026_09_28_100001_move_email_channels_to_mail_transport.php` (raw `DB::table` + `Crypt`, no application model, so it does not depend on classes this change alters; `Crypt::encryptString`/`decryptString` is exactly what the `encrypted` and `encrypted:array` casts use):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $skipped = [];

        DB::table('notification_channels')
            ->where('channel_type', 'email')
            ->whereNotNull('apprise_url')
            ->whereNull('config')
            ->orderBy('id')
            ->each(function (object $row) use (&$skipped): void {
                $url = Crypt::decryptString($row->apprise_url);
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $to = $query['to'] ?? null;

                if (! is_string($to) || $to === '') {
                    $skipped[] = $row->id;

                    return;
                }

                DB::table('notification_channels')->where('id', $row->id)->update([
                    'config' => Crypt::encryptString(json_encode(['to' => $to])),
                    'apprise_url' => null,
                ]);
            });

        if ($skipped !== []) {
            Log::warning('Email channels left unchanged because no recipient address could be read from their stored URL', ['notification_channel_ids' => $skipped]);
        }
    }

    public function down(): void
    {
        $host = config('mail.mailers.smtp.host', 'localhost');
        $port = config('mail.mailers.smtp.port', 587);
        $username = config('mail.mailers.smtp.username');
        $password = config('mail.mailers.smtp.password');
        $from = config('mail.from.address');
        $mode = match (config('mail.mailers.smtp.encryption')) {
            'tls', 'starttls' => 'starttls',
            'ssl' => 'ssl',
            default => 'insecure',
        };
        $auth = $username ? rawurlencode($username).':'.rawurlencode((string) $password).'@' : '';

        DB::table('notification_channels')
            ->where('channel_type', 'email')
            ->whereNull('apprise_url')
            ->whereNotNull('config')
            ->orderBy('id')
            ->each(function (object $row) use ($host, $port, $auth, $from, $mode): void {
                $config = json_decode(Crypt::decryptString($row->config), true);
                $to = is_array($config) ? ($config['to'] ?? null) : null;

                if (! is_string($to) || $to === '') {
                    return;
                }

                $url = "mailto://{$auth}{$host}:{$port}/?to=".rawurlencode($to).'&from='.rawurlencode((string) $from)."&mode={$mode}";

                DB::table('notification_channels')->where('id', $row->id)->update([
                    'apprise_url' => Crypt::encryptString($url),
                    'config' => null,
                ]);
            });
    }
};
```

Note the spec said skipped rows are "reported in the migration output"; migrations have no output channel, so they are logged as a warning naming the row ids instead (a skipped Email channel simply fails deliveries with the fixed "missing its address" message until re-connected).

Before writing the migration, confirm the `notification_channels` raw column values are encrypted strings the way the model casts store them (create a channel in a test and read the raw column) and that `config` for an Apprise-type row is null; adjust only the migration to match reality.

- [ ] **Step 4: Run to verify they pass** — the five files from Step 2 plus `tests/Feature/NotificationChannelResourceTest.php tests/Feature/NotificationChannelConfigMigrationTest.php tests/Feature/MailBrandingTest.php tests/Feature/ChannelMessageMailTest.php tests/Feature/MailTransportTest.php`. Expected: all pass (the earlier `NotificationChannelConfigMigrationTest` still passes: its rollback only refuses while config-only channels exist and does not run our migration).

- [ ] **Step 5: Record the rule** with the `record-rule` tool: glob `app/Support/Delivery/**`, title "Email channels send through Laravel Mail", note: "The Email channel type uses ChannelTransport::Mail: MailTransport sends App\Mail\ChannelMessageMail (a Markdown mailable, body in an escaped <pre>) through the configured default mailer to the encrypted config['to'], never through Apprise. Failure text is fixed (missing address / mail server could not send): an exception message can carry the SMTP host, credentials or the recipient address. The shared mail theme header (resources/views/vendor/mail/html/header.blade.php) renders the logo from APP_URL plus APP_NAME for every Markdown email, including Filament's; keep custom emails on the Markdown layout so they inherit it. Every other channel type stays on Apprise." Afterwards `git checkout .ai/rules/index.md` if the tool reordered unrelated rows there; check it did not duplicate an existing section in `.ai/rules/delivery.md`.

- [ ] **Step 6: Commit** (Pint first; stage `EmailChannelType.php`, `config/services.php`, `.env.example`, the migration, the four touched/new test files and `.ai/rules/delivery.md`; message "Send Email channels through Laravel Mail and migrate existing channels" with the trailer).

---

## Self-Review Notes

- **Spec coverage:** branded header for every Markdown email incl. Filament's → Task 1; `ChannelTransport::Mail`, `MailTransport`, `ChannelMessageMail` with subject fallback, escaped `<pre>` and text alternative, fixed-string failures → Task 2; `EmailChannelType` config destination, existing-channel data migration with `down()` inlined, `from_email` removal, connect/confirmation/Send Test/alert delivery through mail and never Apprise, other types still Apprise → Task 3; migration note (user runs `php artisan migrate`) is a Global Constraint; rule note → Task 3 Step 5.
- **Deliberate deviation:** the spec's "reported in the migration output" for unparsable rows becomes a logged warning (migrations have no output channel).
- **Type consistency:** `ChannelTransport::Mail`, `ChannelMessageMail::$messageTitle/$messageBody`, `MailTransport`, factory `->email()`, `config['to']` are identical across tasks.
