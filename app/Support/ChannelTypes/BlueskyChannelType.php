<?php

namespace App\Support\ChannelTypes;

use App\Exceptions\BlueskyAccountRefused;
use App\Models\NotificationChannel;
use App\Support\BlueskyConsent;
use App\Support\ChannelTypes\Concerns\IsMessagingChannel;
use App\Support\ChannelTypes\Contracts\MessagingChannelType;
use App\Support\Delivery\BlueskyAccountGuard;
use App\Support\Delivery\MessagingMessage;
use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use InvalidArgumentException;

/**
 * Posts each message PUBLICLY to the subscriber's own Bluesky feed (an `app.bsky.feed.post`
 * record) through Apprise's `bsky://{handle}@{app password}` plugin. Only accounts hosted on
 * Bluesky's own servers are supported (owner decision): BlueskyAccountGuard verifies the
 * account when it is saved and, through sendRefusal(), before every send, and the verified DID
 * is stored and is the destination key (two handles or spellings of one account are one channel).
 * There is no server field: the PDS is vetted by the guard, never stored, so serverUrl() is null.
 *
 * Checked against Apprise 2.0.0 (`requests` monkeypatched, no network): with the guarded query
 * Apprise makes its four requests (resolveHandle, the DID document, createSession, createRecord)
 * with redirects off and a (5, 10) s timeout; without it redirects are on and the timeouts 4 s.
 * The body limit is 280 with overflow mode `upstream`: a longer body is sent whole (not cut, not
 * split into a thread) and Bluesky would refuse it, so maxLength() is 280 and compose() never
 * exceeds it. Apprise has no title (title_maxlen 0); none is sent.
 *
 * Apprise decodes the userinfo and the host part (`%40` became `@`, `%2F` became `/`), and the
 * app password sits in the host position, so `rawurlencode()` cannot protect: the handle
 * (domain-style, lower case) and the app password (Bluesky's `xxxx-xxxx-xxxx-xxxx`, lower-case
 * letters and digits) are refused by allow-lists at parse time and again in appriseUrl(), which
 * is empty for a tampered stored value so the transport refuses before any HTTP.
 */
class BlueskyChannelType implements MessagingChannelType
{
    use IsMessagingChannel;

    public const INVALID_HANDLE_MESSAGE = 'Enter your full Bluesky handle, such as yourname.bsky.social.';

    public const INVALID_PASSWORD_MESSAGE = 'Paste an App Password from Bluesky settings. It looks like xxxx-xxxx-xxxx-xxxx.';

    public const CONSENT_REQUIRED_MESSAGE = 'A Bluesky channel needs your agreement that every message is posted publicly.';

    public const ACCOUNT_CHANGED_MESSAGE = 'This handle now belongs to a different Bluesky account. Remove this channel and add it again.';

    public const HANDLE_HELP = 'Your full handle, such as yourname.bsky.social. Only accounts hosted by Bluesky are supported; accounts on other servers are refused.';

    public const PASSWORD_HELP = 'In Bluesky, open Settings → Privacy and security → App Passwords, add one and paste it here. Use an App Password, never your main account password. Anyone with it can post as you, so we keep it encrypted and never show it again.';

    public const CONSENT_HELP = 'The confirmation code is posted publicly too: delete that post once the channel is confirmed.';

    /** Sent instead of the usual test text when a long channel name would not fit. */
    public const TEST_FALLBACK = 'This is a test notification from WxNotify. If you can read this, this Bluesky channel is working correctly.';

    private const HANDLE_PATTERN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D';

    private const PASSWORD_PATTERN = '/^[a-z0-9]{4}(?:-[a-z0-9]{4}){3}$/D';

    public function slug(): string
    {
        return 'bluesky';
    }

    public function label(): string
    {
        return 'Bluesky';
    }

    public function icon(): string
    {
        return 'fab-bluesky';
    }

    /**
     * Apprise's body limit for Bluesky (Bluesky's own is 300 graphemes).
     */
    public function maxLength(): int
    {
        return 280;
    }

    public function formSchema(): array
    {
        return [
            TextInput::make('handle')
                ->label('Handle')
                ->required()
                ->maxLength(254)
                ->rules([self::rule(fn (string $value): bool => self::normalizeHandle($value) !== null, self::INVALID_HANDLE_MESSAGE)])
                ->helperText(self::HANDLE_HELP),
            TextInput::make('app_password')
                ->label('App password')
                ->password()
                ->revealable()
                ->required()
                ->maxLength(64)
                ->rules([self::rule(fn (string $value): bool => preg_match(self::PASSWORD_PATTERN, trim($value)) === 1, self::INVALID_PASSWORD_MESSAGE)])
                ->helperText(self::PASSWORD_HELP),
            Checkbox::make('consent')
                ->label(BlueskyConsent::TEXT)
                ->helperText(self::CONSENT_HELP)
                ->accepted(),
        ];
    }

    /**
     * A Filament closure rule refusing a value the check does not pass (a blank value is left to
     * `required`).
     *
     * @param  Closure(string): bool  $passes
     */
    private static function rule(Closure $passes, string $message): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($passes, $message): void {
            if ($value === null || $value === '') {
                return;
            }

            if (! is_string($value) || ! $passes($value)) {
                $fail($message);
            }
        };
    }

    /**
     * The handle as stored (trimmed, one leading `@` dropped, lower case), or null when it is not
     * a domain-style handle.
     */
    public static function normalizeHandle(string $value): ?string
    {
        $handle = strtolower(trim($value));
        $handle = str_starts_with($handle, '@') ? substr($handle, 1) : $handle;

        return preg_match(self::HANDLE_PATTERN, $handle) === 1 ? $handle : null;
    }

    /**
     * The account is verified here (BlueskyAccountGuard), after the shape checks and the consent,
     * so a refused shape costs no lookup.
     *
     * @param  array<string, mixed>  $data
     * @return array{handle: string, app_password: string, did: string, consent_version: string, consented_at: string}
     *
     * @throws InvalidArgumentException when a value is refused, consent was not given or the
     *                                  account cannot be verified (BlueskyAccountRefused)
     */
    protected function configFromForm(array $data): array
    {
        $handle = self::normalizeHandle((string) ($data['handle'] ?? ''));
        $password = trim((string) ($data['app_password'] ?? ''));

        if ($handle === null) {
            throw new InvalidArgumentException(self::INVALID_HANDLE_MESSAGE);
        }

        if (preg_match(self::PASSWORD_PATTERN, $password) !== 1) {
            throw new InvalidArgumentException(self::INVALID_PASSWORD_MESSAGE);
        }

        if (! filter_var($data['consent'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw new InvalidArgumentException(self::CONSENT_REQUIRED_MESSAGE);
        }

        return [
            'handle' => $handle,
            'app_password' => $password,
            'did' => app(BlueskyAccountGuard::class)->verifiedDidForSave($handle, auth()->id()),
            'consent_version' => BlueskyConsent::VERSION,
            'consented_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return list<string>
     */
    public function requiredConfigKeys(): array
    {
        return ['handle', 'app_password', 'did'];
    }

    /**
     * `bsky://{handle}@{app password}?redirect=no&cto=5&rto=10`, or empty when the stored handle
     * or password is outside its allow-list (a tampered value).
     *
     * @param  array<string, mixed>  $config
     */
    public function appriseUrl(array $config): string
    {
        $handle = $this->storedHandle($config);
        $password = $config['app_password'] ?? null;

        if ($handle === null || ! is_string($password) || preg_match(self::PASSWORD_PATTERN, $password) !== 1) {
            return '';
        }

        return 'bsky://'.rawurlencode($handle).'@'.rawurlencode($password).'?'.$this->guardedQuery();
    }

    /**
     * The account is checked again before every send: it must still be hosted by Bluesky and the
     * handle must still name the stored account. Runs after the URL is known to be untampered,
     * so a refused stored value costs no lookup.
     *
     * @param  array<string, mixed>  $config
     */
    public function sendRefusal(array $config): ?string
    {
        $handle = $this->storedHandle($config);

        if ($handle === null) {
            return 'This channel is missing its settings.';
        }

        try {
            $did = app(BlueskyAccountGuard::class)->verifiedDid($handle);
        } catch (BlueskyAccountRefused $refusal) {
            return $refusal->getMessage();
        }

        return hash_equals($did, (string) ($config['did'] ?? '')) ? null : self::ACCOUNT_CHANGED_MESSAGE;
    }

    /**
     * The stored handle, when it is exactly a normalised handle.
     *
     * @param  array<string, mixed>  $config
     */
    private function storedHandle(array $config): ?string
    {
        $handle = $config['handle'] ?? null;

        return is_string($handle) && self::normalizeHandle($handle) === $handle ? $handle : null;
    }

    /**
     * The chat composition at 280 characters, the View Product link always whole or left out. The
     * confirmation code always fits; a test whose channel name would not fit sends TEST_FALLBACK.
     *
     * @param  array<string, mixed>  $meta
     */
    public function compose(string $body, ?string $title, array $meta): string
    {
        $text = MessagingMessage::chat($body, $title, $meta, $this->maxLength());

        if (mb_strlen($text) <= $this->maxLength()) {
            return $text;
        }

        if (($meta['type'] ?? 'alert') === 'test') {
            return self::TEST_FALLBACK;
        }

        // An over-long confirmation (never in practice): composed as an alert, which shortens the
        // text at a word and keeps the link whole or drops it, never cuts it.
        return MessagingMessage::chat($body, $title, [...$meta, 'type' => 'alert'], $this->maxLength());
    }

    /**
     * Bluesky's PDS is verified by BlueskyAccountGuard, not stored.
     *
     * @param  array<string, mixed>  $config
     */
    public function serverUrl(array $config): ?string
    {
        return null;
    }

    /**
     * The verified DID: every handle and spelling of one account is one destination.
     *
     * @param  array<string, mixed>  $config
     */
    public function destinationKey(array $config): string
    {
        return (string) ($config['did'] ?? '');
    }

    public function destinationNoun(): string
    {
        return 'account';
    }

    /**
     * The handle is public (it is the account's address on Bluesky), so it is shown whole.
     */
    public function displaySummary(NotificationChannel $record): string
    {
        $handle = $this->storedHandle($record->config ?? []);

        return $handle !== null ? '@'.$handle : '—';
    }

    public function destinationLabel(): string
    {
        return 'Bluesky account';
    }

    public function failureMessage(): string
    {
        return 'Bluesky did not accept the post. Check the handle and the app password (an App Password from Bluesky settings, not your account password), and that the account is hosted by Bluesky.';
    }

    public function confirmationHint(NotificationChannel $channel): string
    {
        return 'We just posted a 6-digit code publicly on your Bluesky account. Read it there, then delete that post.';
    }

    public function codeNotSentHint(): string
    {
        return 'We couldn\'t post to that Bluesky account. Check the handle and the app password, and that the account is hosted by Bluesky.';
    }
}
