<?php

namespace App\Support\ChannelTypes;

use App\Models\NotificationChannel;
use App\Support\ChannelTypes\Concerns\IsMessagingChannel;
use App\Support\ChannelTypes\Concerns\MasksDestinationSecrets;
use App\Support\ChannelTypes\Contracts\MessagingChannelType;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use InvalidArgumentException;

/**
 * Opens a PagerDuty incident on the subscriber's service through its Events API v2 integration,
 * via Apprise's `pagerduty://` plugin, which posts to the fixed host of the US region (so there
 * is no server to vet). EU-region accounts are not supported (owner ruling): the URL always
 * carries `region=us` and no region is ever read from `config`.
 *
 * Checked against Apprise 2.0.0 (`requests.post` monkeypatched, no network):
 * `pagerduty://{key}@wxnotify/{source}/{component}?redirect=no&cto=5&rto=10&region=us&severity={s}&image=no`
 * makes exactly one POST (also on a 500, so no retry) to `https://events.pagerduty.com/v2/enqueue`
 * with `allow_redirects=False` and a (5, 10) s timeout, headers `User-Agent: Apprise`,
 * `Content-Type: application/json` and `Authorization: Token token=wxnotify`, and the JSON
 * `{"routing_key": key, "payload": {"summary": body, "severity": s, "source": source,
 * "component": component}, "client": "Apprise", "event_action": "trigger"}`. Without the guarded
 * query redirects are on and the timeouts 4 s; without `image=no` an `images` entry pointing at
 * Apprise's GitHub logo is added. The body is sent whole and unchanged (no `format` in the API
 * request), so `maxLength()` keeps it under PagerDuty's 1024-character `summary` limit.
 *
 * The plugin's template requires an `{apikey}` host part, sent only in that Authorization
 * header, but the Events API v2 authenticates by the routing key in the body and ignores the
 * header. So the host is the fixed API_KEY_PLACEHOLDER: the subscriber is never asked for a
 * REST API key they do not need.
 *
 * Every send is a `trigger` with no `dedup_key`, and nothing ever resolves: each message,
 * including the confirmation code and a test, opens its own incident. Those two go out at
 * `info` (the URL is built per message, see appriseUrlFor()); alerts use the stored severity.
 *
 * Apprise decodes the user part and the path (`%2F` becomes a separator, `%3F` a query), so
 * `rawurlencode()` cannot protect: the key (32 letters and digits, PagerDuty's format) and the
 * source and component (`[A-Za-z0-9._-]`, not only dots, at most 64) are refused by allow-lists
 * at parse time and again in appriseUrl(), which is empty for a tampered stored value so the
 * transport refuses before any HTTP. The severity is matched exactly against the four values:
 * Apprise itself accepts any prefix (`crit`).
 */
class PagerDutyChannelType implements MessagingChannelType
{
    use IsMessagingChannel;
    use MasksDestinationSecrets;

    /** Apprise's required `{apikey}`; the Events API v2 ignores it. */
    public const API_KEY_PLACEHOLDER = 'wxnotify';

    public const SEVERITIES = ['info' => 'Info', 'warning' => 'Warning', 'error' => 'Error', 'critical' => 'Critical'];

    public const DEFAULT_SEVERITY = 'error';

    /** The severity of a confirmation code and a test message. */
    public const NOTICE_SEVERITY = 'info';

    public const DEFAULT_SOURCE = 'WxNotify';

    public const DEFAULT_COMPONENT = 'weather-alert';

    public const KEY_HELP = 'In PagerDuty, open the service, then Integrations → Add an integration → Events API V2, and copy its 32-character Integration Key. US-region PagerDuty accounts only; EU accounts are not supported. Every message opens its own incident, including the confirmation code and test messages, and WxNotify never resolves them: resolve each one in PagerDuty. Anyone with this key can open incidents on the service, so we keep it encrypted and never show it again.';

    public const INVALID_KEY_MESSAGE = 'Paste the 32-character Integration Key of an Events API V2 integration.';

    public const INVALID_NAME_MESSAGE = 'Use up to 64 letters, digits, dots, dashes or underscores.';

    public const INVALID_SEVERITY_MESSAGE = 'Choose Info, Warning, Error or Critical.';

    private const KEY_PATTERN = '/^[A-Za-z0-9]{32}$/D';

    private const NAME_PATTERN = '/^(?!\.+$)[A-Za-z0-9._-]{1,64}$/D';

    public function slug(): string
    {
        return 'pagerduty';
    }

    public function label(): string
    {
        return 'PagerDuty';
    }

    public function icon(): string
    {
        return 'heroicon-o-megaphone';
    }

    /**
     * Below PagerDuty's 1024-character summary limit.
     */
    public function maxLength(): int
    {
        return 1000;
    }

    public function formSchema(): array
    {
        return [
            TextInput::make('integration_key')
                ->label('Integration key')
                ->password()
                ->revealable()
                ->required()
                ->maxLength(64)
                ->rules([self::patternRule(self::KEY_PATTERN, self::INVALID_KEY_MESSAGE, trim: true)])
                ->helperText(self::KEY_HELP),
            Select::make('severity')
                ->label('Severity')
                ->native()
                ->options(self::SEVERITIES)
                ->default(self::DEFAULT_SEVERITY)
                ->selectablePlaceholder(false)
                ->required()
                ->helperText('How urgently each weather alert pages. The confirmation code and test messages are always sent as Info.'),
            TextInput::make('source')
                ->label('Source')
                ->placeholder(self::DEFAULT_SOURCE)
                ->maxLength(64)
                ->rules([self::patternRule(self::NAME_PATTERN, self::INVALID_NAME_MESSAGE)])
                ->helperText('Optional. Shown as the incident\'s source; leave blank for "WxNotify". Letters, digits, dots, dashes and underscores only.'),
            TextInput::make('component')
                ->label('Component')
                ->placeholder(self::DEFAULT_COMPONENT)
                ->maxLength(64)
                ->rules([self::patternRule(self::NAME_PATTERN, self::INVALID_NAME_MESSAGE)])
                ->helperText('Optional. Shown as the incident\'s component; leave blank for "weather-alert". Letters, digits, dots, dashes and underscores only.'),
        ];
    }

    /**
     * A Filament closure rule refusing a value outside the pattern (a blank value is left to
     * `required`).
     */
    private static function patternRule(string $pattern, string $message, bool $trim = false): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($pattern, $message, $trim): void {
            if ($value === null || $value === '') {
                return;
            }

            if (! is_string($value) || preg_match($pattern, $trim ? trim($value) : $value) !== 1) {
                $fail($message);
            }
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{integration_key: string, severity: string, source: string, component: string}
     */
    protected function configFromForm(array $data): array
    {
        $key = trim((string) ($data['integration_key'] ?? ''));
        $severity = blank($data['severity'] ?? null) ? self::DEFAULT_SEVERITY : (string) $data['severity'];
        $source = blank($data['source'] ?? null) ? self::DEFAULT_SOURCE : (string) $data['source'];
        $component = blank($data['component'] ?? null) ? self::DEFAULT_COMPONENT : (string) $data['component'];

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException(self::INVALID_KEY_MESSAGE);
        }

        if (! array_key_exists($severity, self::SEVERITIES)) {
            throw new InvalidArgumentException(self::INVALID_SEVERITY_MESSAGE);
        }

        if (preg_match(self::NAME_PATTERN, $source) !== 1 || preg_match(self::NAME_PATTERN, $component) !== 1) {
            throw new InvalidArgumentException(self::INVALID_NAME_MESSAGE);
        }

        return ['integration_key' => $key, 'severity' => $severity, 'source' => $source, 'component' => $component];
    }

    /**
     * @return list<string>
     */
    public function requiredConfigKeys(): array
    {
        return ['integration_key', 'severity', 'source', 'component'];
    }

    /**
     * The alert URL, at the stored severity.
     *
     * @param  array<string, mixed>  $config
     */
    public function appriseUrl(array $config): string
    {
        return $this->urlAt($config, (string) ($config['severity'] ?? ''));
    }

    /**
     * A confirmation code or a test pages at `info`; an alert at the stored severity. Still empty
     * when a stored value is refused, whatever the message.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $meta
     */
    public function appriseUrlFor(array $config, array $meta): string
    {
        $url = $this->appriseUrl($config);

        if ($url === '' || ! in_array($meta['type'] ?? 'alert', ['confirmation', 'test'], true)) {
            return $url;
        }

        return $this->urlAt($config, self::NOTICE_SEVERITY);
    }

    /**
     * `pagerduty://{key}@wxnotify/{source}/{component}?{guarded query}&region=us&severity={s}&image=no`,
     * or empty when the key, source, component or severity is outside its allow-list (a tampered
     * stored value). `config['region']` is never read.
     *
     * @param  array<string, mixed>  $config
     */
    private function urlAt(array $config, string $severity): string
    {
        $key = $config['integration_key'] ?? null;
        $source = $config['source'] ?? null;
        $component = $config['component'] ?? null;

        if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1
            || ! is_string($source) || preg_match(self::NAME_PATTERN, $source) !== 1
            || ! is_string($component) || preg_match(self::NAME_PATTERN, $component) !== 1
            || ! array_key_exists($severity, self::SEVERITIES)) {
            return '';
        }

        $query = $this->guardedQuery(['region' => 'us', 'severity' => $severity, 'image' => 'no']);

        return 'pagerduty://'.rawurlencode($key).'@'.self::API_KEY_PLACEHOLDER.'/'.rawurlencode($source).'/'.rawurlencode($component).'?'.$query;
    }

    /**
     * The host is always the US region's events.pagerduty.com.
     *
     * @param  array<string, mixed>  $config
     */
    public function serverUrl(array $config): ?string
    {
        return null;
    }

    /**
     * The trimmed key in lower case, normalised here because BeginChannelConnection compares the
     * raw string. Ruleset keys mix cases; folding them can only refuse a duplicate too many.
     *
     * @param  array<string, mixed>  $config
     */
    public function destinationKey(array $config): string
    {
        return strtolower(trim((string) ($config['integration_key'] ?? '')));
    }

    public function destinationNoun(): string
    {
        return 'integration key';
    }

    public function displaySummary(NotificationChannel $record): string
    {
        $key = $record->config['integration_key'] ?? null;

        return is_string($key) && $key !== '' ? self::maskLast4($key) : '—';
    }

    public function destinationLabel(): string
    {
        return 'PagerDuty service';
    }

    public function failureMessage(): string
    {
        return 'PagerDuty did not accept the event. Check the integration key, and that it is an Events API v2 key on a US-region PagerDuty account.';
    }

    public function confirmationHint(NotificationChannel $channel): string
    {
        return 'We just sent a 6-digit code to your PagerDuty service. It opens an incident there: read the code from it, then resolve the incident.';
    }

    public function codeNotSentHint(): string
    {
        return 'We couldn\'t send to that PagerDuty integration. Check the integration key, and that it is an Events API v2 integration on a US-region PagerDuty account.';
    }
}
