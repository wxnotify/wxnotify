<?php

namespace App\Support\ChannelTypes;

use App\Models\NotificationChannel;
use App\Support\ChannelTypes\Concerns\MasksDestinationSecrets;
use App\Support\ChannelTypes\Concerns\UsesAppriseTransport;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Js;

class PushoverChannelType implements ChannelType
{
    use MasksDestinationSecrets, UsesAppriseTransport;

    public const DEFAULT_PRIORITY = '0';

    public const EMERGENCY_PRIORITY = '2';

    public const DEFAULT_SOUND = 'pushover';

    public const DEFAULT_RETRY_INTERVAL = 900;

    public const DEFAULT_EXPIRE = 3600;

    /** @var array<string, string> */
    public const PRIORITIES = [
        '-2' => '-2 (lowest)',
        '-1' => '-1 (low)',
        '0' => '0 (normal)',
        '1' => '1 (high)',
        '2' => '2 (emergency)',
    ];

    /**
     * Pushover's built-in sounds: the API value => [label, preview file on
     * api.pushover.net/sounds]. "vibrate" and "none" have no audio.
     *
     * @var array<string, array{0: string, 1: ?string}>
     */
    public const SOUNDS = [
        'pushover' => ['Pushover (default)', 'po'],
        'bike' => ['Bike', 'bk'],
        'bugle' => ['Bugle', 'bu'],
        'cashregister' => ['Cash Register', 'ch'],
        'classical' => ['Classical', 'cl'],
        'cosmic' => ['Cosmic', 'co'],
        'falling' => ['Falling', 'fa'],
        'gamelan' => ['Gamelan', 'gl'],
        'incoming' => ['Incoming', 'ic'],
        'intermission' => ['Intermission', 'im'],
        'magic' => ['Magic', 'ma'],
        'mechanical' => ['Mechanical', 'mc'],
        'pianobar' => ['Piano Bar', 'pn'],
        'siren' => ['Siren', 'si'],
        'spacealarm' => ['Space Alarm', 'sp'],
        'tugboat' => ['Tug Boat', 'tg'],
        'alien' => ['Alien Alarm (long)', 'ln'],
        'climb' => ['Climb (long)', 'mb'],
        'persistent' => ['Persistent (long)', 'ps'],
        'echo' => ['Pushover Echo (long)', 'ec'],
        'updown' => ['Up Down (long)', 'ud'],
        'vibrate' => ['Vibrate only', null],
        'none' => ['None (silent)', null],
    ];

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
            Textarea::make('devices')
                ->label('Devices')
                ->rows(2)
                ->rules([fn (): Closure => self::deviceNamesRule()])
                ->helperText('Optional. Device names separated by commas, spaces or new lines. Leave empty to send to all of your devices.'),
            Select::make('priority')
                ->label('Priority')
                ->native()
                ->options(self::PRIORITIES)
                ->default(self::DEFAULT_PRIORITY)
                ->required()
                ->live()
                ->helperText('Emergency messages repeat until you acknowledge them in the Pushover app, and so does the confirmation code we send you.'),
            Select::make('sound')
                ->label('Sound')
                ->native()
                ->options(array_map(fn (array $sound): string => $sound[0], self::SOUNDS))
                ->default(self::DEFAULT_SOUND)
                ->required()
                ->suffixAction(self::playSoundAction()),
            TextInput::make('retry_interval')
                ->label('Emergency retry interval (seconds)')
                ->numeric()
                ->integer()
                ->minValue(30)
                ->maxValue(10800)
                ->default(self::DEFAULT_RETRY_INTERVAL)
                ->required()
                ->visible(fn (Get $get): bool => (string) $get('priority') === self::EMERGENCY_PRIORITY)
                ->helperText('How often Pushover repeats an unacknowledged emergency message. At least 30.'),
            TextInput::make('expire')
                ->label('Expire (seconds)')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(10800)
                ->default(self::DEFAULT_EXPIRE)
                ->required()
                ->visible(fn (Get $get): bool => (string) $get('priority') === self::EMERGENCY_PRIORITY)
                ->helperText('How long Pushover keeps repeating an unacknowledged emergency message. At most 10800 (3 hours).'),
        ];
    }

    public function buildAppriseUrl(array $data): string
    {
        $devices = collect(self::parseDevices($data['devices'] ?? null))
            ->map(fn (string $device): string => '/'.rawurlencode($device))
            ->implode('');

        $priority = (string) ($data['priority'] ?? self::DEFAULT_PRIORITY);
        $sound = (string) ($data['sound'] ?? self::DEFAULT_SOUND);

        $query = array_filter([
            'priority' => $priority !== self::DEFAULT_PRIORITY ? $priority : null,
            'sound' => $sound !== self::DEFAULT_SOUND ? $sound : null,
        ]);

        if ($priority === self::EMERGENCY_PRIORITY) {
            $query += array_filter([
                'interval' => ($data['retry_interval'] ?? self::DEFAULT_RETRY_INTERVAL) != self::DEFAULT_RETRY_INTERVAL
                    ? (int) $data['retry_interval'] : null,
                'expire' => ($data['expire'] ?? self::DEFAULT_EXPIRE) != self::DEFAULT_EXPIRE
                    ? (int) $data['expire'] : null,
            ]);
        }

        $queryString = $query === [] ? '' : '?'.http_build_query($query);

        return "pover://{$data['user_key']}@{$data['api_token']}{$devices}{$queryString}";
    }

    public function supportsRichContent(): bool
    {
        return false;
    }

    /**
     * @return array<int, string>
     */
    public static function parseDevices(?string $devices): array
    {
        return array_values(array_unique(preg_split('/[\s,]+/', (string) $devices, -1, PREG_SPLIT_NO_EMPTY)));
    }

    private static function deviceNamesRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            foreach (self::parseDevices(is_string($value) ? $value : null) as $device) {
                if (! preg_match('/^[A-Za-z0-9_-]{1,25}$/', $device)) {
                    $fail('Device names are up to 25 letters, numbers, underscores or dashes.');

                    return;
                }
            }
        };
    }

    private static function playSoundAction(): Action
    {
        $files = Js::from(array_map(fn (array $sound): ?string => $sound[1], self::SOUNDS));

        return Action::make('playPushoverSound')
            ->label('Play sound')
            ->tooltip('Play this sound')
            ->icon(Heroicon::Play)
            ->alpineClickHandler(<<<JS
                (() => {
                    const files = {$files};
                    const file = files[\$el.closest('.fi-fo-field')?.querySelector('select')?.value];
                    window.wxPushoverPreview?.pause();
                    if (! file) { return; }
                    window.wxPushoverPreview = new Audio('https://api.pushover.net/sounds/' + file + '.mp3');
                    window.wxPushoverPreview.play();
                })()
                JS);
    }

    public function countsTowardFreeSlot(): bool
    {
        return true;
    }

    public function displaySummary(NotificationChannel $record): string
    {
        if (! preg_match('#^pover://([A-Za-z0-9]+)@#', (string) $record->apprise_url, $matches)) {
            return '—';
        }

        return self::maskLast4($matches[1]);
    }
}
