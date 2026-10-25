<?php

namespace App\Support\ChannelTypes;

use App\Models\NotificationChannel;
use App\Support\ChannelTypes\Concerns\IsMessagingChannel;
use App\Support\ChannelTypes\Concerns\MasksDestinationSecrets;
use App\Support\ChannelTypes\Contracts\MessagingChannelType;
use App\Support\Delivery\MessagingMessage;
use Closure;
use Filament\Forms\Components\TextInput;
use InvalidArgumentException;

/**
 * Fires an IFTTT Webhooks event on the subscriber's own IFTTT account, via Apprise's `ifttt://`
 * plugin, which posts to the fixed host `maker.ifttt.com` (so there is no server to vet). What
 * happens next is the subscriber's applet ("If Webhooks receives a web request with event
 * {event}, then ...").
 *
 * Checked against Apprise 2.0.0 (`requests.post` monkeypatched, no network):
 * `ifttt://{key}/{event}?redirect=no&cto=5&rto=10` makes exactly one POST (also on a 500, so no
 * retry) to `https://maker.ifttt.com/trigger/{event}/with/key/{key}` with
 * `allow_redirects=False`, a (5, 10) s timeout, headers `User-Agent: Apprise` and
 * `Content-Type: application/json`, and the JSON `{"value1": title, "value2": body,
 * "value3": "info"}` (the notify type; WxNotify always sends `info`). Without the guarded query
 * redirects are on and both timeouts 4 s. The key and event keep their case. The plugin knows no
 * `image` argument, so none is sent. Apprise cuts nothing (overflow `upstream`; its title_maxlen
 * of 250 and body_maxlen of 32768 are not enforced), so the title is cut here at
 * TITLE_MAX_LENGTH and the body at maxLength().
 *
 * This is the one messaging type with a title of its own (appriseTitle()): value1 is the title
 * the dispatcher passes (already carrying the admin message prefix; "Confirm your WxNotify
 * channel" for the code, "WxNotify test notification" for a test), and value2 is compose()'s
 * text without the title: the summary and then the unbroken View Product link for an alert, the
 * unchanged body for the code and a test.
 *
 * Apprise decodes the path and puts the key and event into the IFTTT URL as they are (`%3F`
 * in either became a raw `?` in the URL it posted to; `%2F`, a comma or a space in the event
 * fired two events), and splits events on `/`, `,` and whitespace, so `rawurlencode()` cannot
 * protect: the key (`[A-Za-z0-9_-]{16,64}`: IFTTT's keys are URL-safe letters, digits, `-` and
 * `_`; 22 and 44 characters are both reported) and the event (`[A-Za-z0-9_]{1,64}`, IFTTT's own
 * "letters, numbers, and underscores") are refused by allow-lists at parse time and again in
 * appriseUrl(), which is empty for a tampered stored value so the transport refuses before any
 * HTTP.
 */
class IftttChannelType implements MessagingChannelType
{
    use IsMessagingChannel;
    use MasksDestinationSecrets;

    public const DEFAULT_EVENT = 'wxnotify_alert';

    /** Apprise's IFTTT title_maxlen. */
    public const TITLE_MAX_LENGTH = 250;

    /** value1 when a message has no title of its own. */
    public const TITLE_FALLBACK = 'WxNotify';

    public const KEY_HELP = 'On https://ifttt.com/maker_webhooks open Documentation and copy your key from the top of that page. Anyone with this key can trigger every Webhooks applet on your IFTTT account, so we keep it encrypted and never show it again.';

    public const EVENT_HELP = 'Optional: leave blank for wxnotify_alert. Letters, digits and underscores only, spelled exactly as in your applet (IFTTT treats the case as part of the name). Create an IFTTT applet "If Webhooks receives a web request with event wxnotify_alert (or your name), then ..." with an action that reaches you, such as a notification or an email. Value1 is the alert title, Value2 the summary ending with the View Product link, and Value3 is always "info". The confirmation code arrives as Value2, so the applet must deliver it somewhere you can read it. No alerts are sent until the channel is confirmed. IFTTT plans limit how many applets you can have and how quickly they run: check yours.';

    public const INVALID_KEY_MESSAGE = 'Paste the Webhooks key from IFTTT: letters, digits, dashes and underscores only.';

    public const INVALID_EVENT_MESSAGE = 'Use up to 64 letters, digits or underscores, exactly as in your applet.';

    private const KEY_PATTERN = '/^[A-Za-z0-9_-]{16,64}$/D';

    private const EVENT_PATTERN = '/^[A-Za-z0-9_]{1,64}$/D';

    public function slug(): string
    {
        return 'ifttt';
    }

    public function label(): string
    {
        return 'IFTTT';
    }

    public function icon(): string
    {
        return 'heroicon-o-puzzle-piece';
    }

    /**
     * Value2's length: an applet's notification or email has no use for a whole bulletin.
     */
    public function maxLength(): int
    {
        return 1000;
    }

    public function formSchema(): array
    {
        return [
            TextInput::make('webhooks_key')
                ->label('Webhooks key')
                ->password()
                ->revealable()
                ->required()
                ->maxLength(64)
                ->rules([self::patternRule(self::KEY_PATTERN, self::INVALID_KEY_MESSAGE)])
                ->helperText(self::KEY_HELP),
            TextInput::make('event')
                ->label('Event name')
                ->placeholder(self::DEFAULT_EVENT)
                ->maxLength(64)
                ->rules([self::patternRule(self::EVENT_PATTERN, self::INVALID_EVENT_MESSAGE)])
                ->helperText(self::EVENT_HELP),
        ];
    }

    /**
     * A Filament closure rule refusing a trimmed value outside the pattern (a blank value is
     * left to `required` or the default).
     */
    private static function patternRule(string $pattern, string $message): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($pattern, $message): void {
            if ($value === null || $value === '') {
                return;
            }

            if (! is_string($value) || preg_match($pattern, trim($value)) !== 1) {
                $fail($message);
            }
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{webhooks_key: string, event: string}
     */
    protected function configFromForm(array $data): array
    {
        $key = trim((string) ($data['webhooks_key'] ?? ''));
        $event = trim((string) ($data['event'] ?? ''));
        $event = $event === '' ? self::DEFAULT_EVENT : $event;

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException(self::INVALID_KEY_MESSAGE);
        }

        if (preg_match(self::EVENT_PATTERN, $event) !== 1) {
            throw new InvalidArgumentException(self::INVALID_EVENT_MESSAGE);
        }

        return ['webhooks_key' => $key, 'event' => $event];
    }

    /**
     * @return list<string>
     */
    public function requiredConfigKeys(): array
    {
        return ['webhooks_key', 'event'];
    }

    /**
     * `ifttt://{key}/{event}?{guarded query}`, or empty when the stored key or event is outside
     * its allow-list (a tampered value).
     *
     * @param  array<string, mixed>  $config
     */
    public function appriseUrl(array $config): string
    {
        $key = $config['webhooks_key'] ?? null;
        $event = $config['event'] ?? null;

        if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1
            || ! is_string($event) || preg_match(self::EVENT_PATTERN, $event) !== 1) {
            return '';
        }

        return 'ifttt://'.rawurlencode($key).'/'.rawurlencode($event).'?'.$this->guardedQuery();
    }

    /**
     * Value2: the chat text without the title (summary, then the whole link), or the code or
     * test body unchanged.
     *
     * @param  array<string, mixed>  $meta
     */
    public function compose(string $body, ?string $title, array $meta): string
    {
        return MessagingMessage::chat($body, null, $meta, $this->maxLength());
    }

    /**
     * Value1: the title as the dispatcher passes it (message prefix included), on one line.
     *
     * @param  array<string, mixed>  $meta
     */
    public function appriseTitle(?string $title, array $meta): ?string
    {
        return MessagingMessage::heading($title, self::TITLE_MAX_LENGTH, self::TITLE_FALLBACK);
    }

    /**
     * The host is always maker.ifttt.com.
     *
     * @param  array<string, mixed>  $config
     */
    public function serverUrl(array $config): ?string
    {
        return null;
    }

    /**
     * The trimmed key and event, case kept (both are case-sensitive at IFTTT, so nothing else is
     * provably the same destination), normalised here because BeginChannelConnection compares
     * the raw string.
     *
     * @param  array<string, mixed>  $config
     */
    public function destinationKey(array $config): string
    {
        $key = trim((string) ($config['webhooks_key'] ?? ''));
        $event = trim((string) ($config['event'] ?? ''));

        return $key === '' ? '' : $key.'/'.$event;
    }

    public function destinationNoun(): string
    {
        return 'Webhooks key and event';
    }

    public function displaySummary(NotificationChannel $record): string
    {
        $key = $record->config['webhooks_key'] ?? null;
        $event = $record->config['event'] ?? null;

        if (! is_string($key) || $key === '' || ! is_string($event) || $event === '') {
            return '—';
        }

        return $event.' · '.self::maskLast4($key);
    }

    public function destinationLabel(): string
    {
        return 'IFTTT Webhooks event';
    }

    public function failureMessage(): string
    {
        return 'IFTTT did not accept the event. Check the Webhooks key and the event name, and that the Webhooks service is still connected to your IFTTT account.';
    }

    public function confirmationHint(NotificationChannel $channel): string
    {
        return 'We just triggered your IFTTT event with a 6-digit code as Value2. Read it wherever your applet delivers it.';
    }

    public function codeNotSentHint(): string
    {
        return 'We couldn\'t trigger that IFTTT event. Check the Webhooks key and the event name.';
    }
}
