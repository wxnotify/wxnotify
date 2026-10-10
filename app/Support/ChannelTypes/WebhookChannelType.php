<?php

namespace App\Support\ChannelTypes;

use App\Enums\ChannelTransport;
use App\Models\NotificationChannel;
use App\Rules\SafeWebhookHeaders;
use App\Rules\SafeWebhookProxyUrl;
use App\Rules\SafeWebhookUrl;
use App\Support\ChannelTypes\Concerns\NotCreatableInApp;
use App\Support\ChannelTypes\Contracts\ChannelType;
use App\Support\Delivery\Transports\WebhookTransport;
use App\Support\Delivery\WebhookHeaderGuard;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class WebhookChannelType implements ChannelType
{
    use NotCreatableInApp;

    /**
     * Every key a webhook channel's encrypted `config` may hold, with the value
     * a channel that predates the option gets. Channels created before these
     * options existed hold only `url` and `secret`; withDefaults() is the single
     * place that turns such a config into a complete one, and the transport, the
     * wizard and the edit form all read through it.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'url' => null,
        'method' => 'POST',
        'sign' => true,
        'secret' => null,
        'verify_ssl' => true,
        'use_timestamp' => true,
        'tries' => 3,
        'timeout' => 5,
        'headers' => [],
        'use_proxy' => false,
        'proxy_url' => null,
    ];

    /** @var list<string> */
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH'];

    public const MIN_TRIES = 1;

    public const MAX_TRIES = 5;

    public const MIN_TIMEOUT = 1;

    public const MAX_TIMEOUT = 30;

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

    /**
     * A stored config with every missing option filled in, and every present
     * one coerced to the type and range the transport expects. Nothing
     * downstream has to re-check bounds it was given.
     *
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>
     */
    public static function withDefaults(?array $config): array
    {
        $config = array_filter($config ?? [], static fn (mixed $value): bool => $value !== null);
        $config = [...self::DEFAULTS, ...$config];

        $method = strtoupper((string) $config['method']);

        $config['method'] = in_array($method, self::METHODS, true) ? $method : self::DEFAULTS['method'];
        $config['sign'] = (bool) $config['sign'];
        $config['verify_ssl'] = (bool) $config['verify_ssl'];
        $config['use_timestamp'] = (bool) $config['use_timestamp'];
        $config['use_proxy'] = (bool) $config['use_proxy'];
        $config['tries'] = max(self::MIN_TRIES, min(self::MAX_TRIES, (int) $config['tries']));
        $config['timeout'] = max(self::MIN_TIMEOUT, min(self::MAX_TIMEOUT, (int) $config['timeout']));
        $config['headers'] = is_array($config['headers']) ? $config['headers'] : [];

        return $config;
    }

    /**
     * Turn the FLAT form data (`method`, `sign`, …) into the stored config,
     * merged over whatever the channel already had.
     *
     * Only keys the form actually submitted are taken: a field Filament hid
     * (the secret while signing is off) is simply absent from $data, and the
     * existing value must survive rather than be blanked. Deliberately flat on
     * the form side — a dotted `config.*` field would dehydrate to an empty
     * `config` array and wipe the column (see .ai/rules/filament.md).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    public static function configFromForm(array $data, array $existing = []): array
    {
        $config = self::withDefaults($existing);

        foreach (array_keys(self::DEFAULTS) as $key) {
            if (array_key_exists($key, $data)) {
                $config[$key] = $data[$key];
            }
        }

        $config = self::withDefaults($config);

        // A proxy the subscriber turned off is forgotten, not kept dormant.
        if (! $config['use_proxy']) {
            $config['proxy_url'] = null;
        }

        // Signing can be switched back on later; a channel always has a secret.
        if (blank($config['secret'])) {
            $config['secret'] = self::generateSecret();
        }

        return $config;
    }

    /**
     * The flat form state for an existing channel's config.
     *
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>
     */
    public static function formFromConfig(?array $config): array
    {
        $state = self::withDefaults($config);

        if (blank($state['secret'])) {
            $state['secret'] = self::generateSecret();
        }

        return $state;
    }

    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function formSchema(): array
    {
        return self::fields();
    }

    /**
     * The same fields the wizard shows, for the channel's edit form. The caller
     * is responsible for only rendering them on a webhook channel — they are
     * wrapped in one container there rather than each carrying a second
     * ->visible(), which would collide with the conditions below.
     *
     * @return array<Component>
     */
    public static function editFields(): array
    {
        return self::fields();
    }

    /**
     * @return array<Component>
     */
    private static function fields(): array
    {
        return [
            TextInput::make('url')
                ->label('Webhook URL')
                ->url()
                ->required()
                ->rule(new SafeWebhookUrl)
                ->helperText('WxNotify sends to this https:// URL. Redirects are never followed.')
                ->columnSpanFull(),

            self::payloadCallout(),

            Select::make('method')
                ->label('HTTP verb')
                ->options(array_combine(self::METHODS, self::METHODS))
                ->default(self::DEFAULTS['method'])
                ->required()
                ->in(self::METHODS)
                ->helperText('GET sends the alert as a query string with no request body; the signature is still computed over the JSON payload.'),

            TextInput::make('timeout')
                ->label('Timeout (seconds)')
                ->numeric()
                ->integer()
                ->default(self::DEFAULTS['timeout'])
                ->required()
                ->minValue(self::MIN_TIMEOUT)
                ->maxValue(self::MAX_TIMEOUT)
                ->helperText('How long one attempt may take before it counts as failed.'),

            TextInput::make('tries')
                ->label('Number of tries')
                ->numeric()
                ->integer()
                ->default(self::DEFAULTS['tries'])
                ->required()
                ->minValue(self::MIN_TRIES)
                ->maxValue(self::MAX_TRIES)
                ->helperText('Attempts per message. We wait a second after the first failure and three seconds after each later one, and only retry a connection error, a 5xx, a 408 or a 429.'),

            Toggle::make('sign')
                ->label('Sign request')
                ->default(self::DEFAULTS['sign'])
                ->live()
                ->helperText('Adds a "Signature" header: HMAC-SHA256 of the raw request body, keyed with the signing secret. Turn it off and no "Signature" header is sent at all.'),

            TextInput::make('secret')
                ->label('Signing secret')
                ->password()
                ->revealable()
                ->default(fn (): string => self::generateSecret())
                ->required(fn (Get $get): bool => (bool) $get('sign'))
                ->maxLength(255)
                ->visible(fn (Get $get): bool => (bool) $get('sign'))
                ->helperText('Copy this into your receiver; you can reveal it again here at any time.'),

            Toggle::make('verify_ssl')
                ->label('Verify SSL')
                ->default(self::DEFAULTS['verify_ssl'])
                ->helperText('Leave on. Turning it off accepts any certificate, so anyone able to intercept the connection can read and alter the alert.'),

            Toggle::make('use_timestamp')
                ->label('Use timestamp')
                ->default(self::DEFAULTS['use_timestamp'])
                ->helperText('Adds a "Timestamp" header holding Unix seconds. It is not covered by the signature.'),

            KeyValue::make('headers')
                ->label('Extra headers')
                ->keyLabel('Header')
                ->valueLabel('Value')
                ->default(self::DEFAULTS['headers'])
                ->rule(new SafeWebhookHeaders)
                ->helperText('Up to '.WebhookHeaderGuard::MAX_HEADERS.' extra headers, e.g. Authorization. Headers WxNotify sets itself (Content-Type, Signature, Timestamp, Host, …) cannot be overridden.')
                ->columnSpanFull(),

            Toggle::make('use_proxy')
                ->label('Use proxy')
                ->default(self::DEFAULTS['use_proxy'])
                ->live()
                ->helperText('Send through an outbound HTTP proxy instead of connecting directly.'),

            TextInput::make('proxy_url')
                ->label('Proxy URL')
                ->required(fn (Get $get): bool => (bool) $get('use_proxy'))
                ->rule(new SafeWebhookProxyUrl)
                ->visible(fn (Get $get): bool => (bool) $get('use_proxy'))
                ->maxLength(255)
                ->helperText('http:// or https://, on port 80, 443 or anything above 1023 (3128 and 8080 are typical). May include user:password@. The webhook URL itself is still checked.'),
        ];
    }

    /**
     * What a receiver will actually get — webhooks carry a structured payload,
     * unlike the Apprise channels which just get text.
     *
     * The key names come from WebhookTransport's own constants so this text
     * cannot drift away from the payload the transport builds.
     */
    private static function payloadCallout(): Callout
    {
        $alertKeys = implode(', ', array_map(fn (string $key): string => "<code>{$key}</code>", WebhookTransport::ALERT_PAYLOAD_KEYS));
        $code = '<code>'.WebhookTransport::CONFIRMATION_ONLY_KEY.'</code>';

        return Callout::make('What WxNotify sends to your endpoint')
            ->key('payload_description')
            ->icon('heroicon-o-code-bracket')
            ->description(new HtmlString(implode(' ', [
                'A JSON object with '.$alertKeys.'.',
                '<code>type</code> is "alert" or "confirmation";',
                '<code>title</code> is the alert subject and <code>body</code> is the full alert text — the same text an email or Discord channel would receive;',
                '<code>sent_at</code> is an ISO 8601 UTC timestamp;',
                'and for an alert, <code>alert</code> is an object of details such as <code>rule</code> and <code>product</code>.',
                'The confirmation message sent when this channel is connected has <code>type</code> "confirmation" and a top-level '.$code.' holding the 6-digit code to type back, in place of <code>alert</code>.',
                'With POST, PUT or PATCH these fields are the JSON request body; with GET they are sent as a query string instead, so there is no body and the message length is capped by URL limits.',
            ])))
            ->columnSpanFull();
    }

    public function transport(): ChannelTransport
    {
        return ChannelTransport::Webhook;
    }

    public function buildDestination(array $data): array
    {
        return [
            'config' => self::configFromForm($data),
            'label' => 'Webhook ('.Str::limit((string) parse_url($data['url'] ?? '', PHP_URL_HOST), 40, '').')',
        ];
    }

    public function countsTowardFreeSlot(): bool
    {
        return true;
    }

    /**
     * False: connecting a webhook is a multi-option setup step ending in a
     * confirmation code, and the alert-rule form's inline create has no room
     * for either.
     */
    public function supportsInlineCreate(): bool
    {
        return false;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function supportsRichContent(): bool
    {
        return false;
    }

    /**
     * The host only -- never the full URL (path/query may carry a token)
     * and never config['secret'].
     */
    public function displaySummary(NotificationChannel $record): string
    {
        $host = parse_url((string) ($record->config['url'] ?? ''), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : '—';
    }

    public function destinationLabel(): string
    {
        return 'Webhook host';
    }
}
