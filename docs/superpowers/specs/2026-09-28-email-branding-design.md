# Email Branding — Design

Source: an admin request that every email the system sends use Laravel's Markdown mail templates, show the logo (`public/images/logo.png`) and the app name (`APP_NAME`) at the top, and that alert emails show the message body in a pre-formatted font.

## Goal

Every email leaving the system carries the same branded header, and alert emails sent through a subscriber's Email channel are rendered as Laravel Markdown Mailables with the message body in a pre-formatted block.

## Findings that shape the design

- The Email channel does not use Laravel mail today. `EmailChannelType` builds a `mailto://…` URL from the SMTP settings and `AppriseTransport` hands it to the Apprise API, which sends the message itself, so no Laravel template is involved. Alerts, confirmation codes and Send Test messages all go through `ChannelDispatcher`, so all three take this path.
- The other emails (Filament's email verification and password reset, and any future `MailMessage` notification) are Laravel notifications rendered through the shared `notifications::email` Markdown template, which uses the shared mail theme (`resources/views/vendor/mail`, not yet published).
- The channel architecture already supports config-based destinations with their own transport (`ChannelTransport::Webhook`, `ChannelTransport::WebPush`; `buildDestination()` may return `config`, and `notification_channels.apprise_url` is nullable).

## Scope

In: the branded mail theme header, a new `ChannelTransport::Mail` and `MailTransport`, a `ChannelMessageMail` Markdown mailable, `EmailChannelType` switching to config-based destinations, a data migration for existing Email channels, removal of the now-unused Apprise email `from` setting, tests.

Out: any non-email channel (they stay on Apprise), admin activity alerts (`ActivityAlertRule::apprise_target`, a generic Apprise target), redesigning the theme's colors or footer, HTML email for anything beyond the shared header and the channel mailable, and unsubscribe links.

## Decisions (user-approved)

- Logo delivered as an absolute URL built from `APP_URL` (`asset('images/logo.png')`), not embedded.
- Confirmation-code and test-send emails use the pre-formatted body too, not only alerts: everything sent through an Email channel uses `ChannelMessageMail`.
- The Email channel moves to a Laravel Mail transport (required for Markdown Mailables).

## Branding (all emails)

Publish Laravel's mail views (`php artisan vendor:publish --tag=laravel-mail`) into `resources/views/vendor/mail` and customize only the header: `html/header.blade.php` renders the logo (`asset('images/logo.png')`, with explicit `height`/`alt` attributes because email clients ignore CSS sizing) next to `config('app.name')`, both linking to the same URL as today; `text/header.blade.php` renders the app name. Everything else in the published theme is left as published. Every Markdown-rendered email inherits this: the new mailable, Filament's verification and reset emails, and any later notification, with no code changes to them.

Deployment note: the logo only loads for recipients when `APP_URL` is the public address; in dev, Mailpit previews load it from the host.

## Channel emails

- `ChannelTransport` gains `Mail = 'mail'`. `ChannelDispatcher::transportFor()` maps it to `App\Support\Delivery\Transports\MailTransport`.
- `MailTransport::send(NotificationChannel $channel, string $body, ?string $title, array $meta = []): DeliveryResult` reads `config['to']`; when blank it returns a fixed failure ("This email channel is missing its address."). Otherwise it sends `Mail::to($to)->send(new ChannelMessageMail($title, $body))` through the configured default mailer (so the admin-editable email settings and the `.env` SMTP settings apply). Any `Throwable` becomes `DeliveryResult::failed('The mail server could not send the message.')`: per `.ai/rules/delivery.md`, an exception message, address or SMTP detail never reaches the error text.
- `App\Mail\ChannelMessageMail` is a Markdown mailable (`Illuminate\Mail\Mailable` with `content()` returning `markdown: 'mail.channel-message'`). Subject: the title, falling back to `config('app.name')`. The view puts the body in an escaped `<pre>` block inside the mail component, styled with `white-space: pre-wrap; word-wrap: break-word; font-family` monospace, so long lines wrap and product formatting is kept. The body is escaped (`{{ }}`); the view avoids indentation that Markdown would turn into code blocks. The plain-text alternative is generated from the same template.
- `EmailChannelType` stops using `UsesAppriseTransport`: `transport()` returns `ChannelTransport::Mail`, `buildDestination(array $data)` returns `['config' => ['to' => $data['to']]]`, and it implements `supportsInlineCreate()` and `isAvailable()` (both true) itself. `buildAppriseUrl()` is removed. `config['to']` is stored in the existing encrypted `config` column.
- The From address and name are Laravel's `mail.from` (`MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, which is `APP_NAME`). `config('services.apprise.from_email')` (`APPRISE_FROM_EMAIL`) had no other reader, so it and its `.env.example` line are removed.
- Confirmation codes, Send Test and alert delivery need no change beyond this: they already go through `ChannelDispatcher`.

## Existing Email channels (data migration)

A data migration finds every `notification_channels` row with `channel_type = 'email'` and a non-null `apprise_url`, parses the `to` query parameter out of the decrypted `mailto://` URL, sets `config = ['to' => <address>]` and `apprise_url = null`, leaving `verified_at` and every other column untouched. A row whose URL has no parsable `to` is left unchanged and reported in the migration output. `down()` rebuilds each row's `apprise_url` from `config['to']` and the current mail config in the same `mailto://…` format and clears `config`; the URL format is inlined in the migration so it does not depend on application classes that this change alters or removes. The migration is not run on the dev DB by us; the user runs `php artisan migrate`.

## Security and safety

- The recipient address moves from the encrypted `apprise_url` into the encrypted `config` column; neither is logged (the `NotificationChannel` activity log already excludes both).
- `MailTransport` and the mailable never put an address, exception text or SMTP detail into a `DeliveryResult`, log line or failure output.
- The body is escaped, so product or alert text cannot inject HTML into the email.

## Testing

- Theme: rendering a notification `MailMessage` (Filament's verification email) and `ChannelMessageMail` each produce HTML containing the logo URL (`…/images/logo.png`) and the app name, and the plain-text version contains the app name.
- `ChannelMessageMail`: subject from the title with the app-name fallback; the body appears inside an escaped `<pre>` (HTML in the body is escaped, line breaks preserved); markdown-significant characters and indentation do not change it.
- `MailTransport`: with `Mail::fake()`, sends one message to `config['to']` with the right subject and body; a missing address returns the fixed failure; a mailer exception returns the fixed failure and no exception text or address appears in the result.
- `EmailChannelType`: `buildDestination` returns config only and `transport()` is `Mail`; the connect flow still creates and verifies an Email channel; Send Test, the confirmation code and alert delivery reach `Mail::fake()` and never call the Apprise client for an Email channel; other channel types still use Apprise.
- Data migration: existing `mailto://` Email channels get `config['to']` and a null `apprise_url` with `verified_at` unchanged; an unparsable row is left alone; `down()` restores a working `mailto://` URL; non-email channels are untouched.
