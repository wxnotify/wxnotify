<?php

namespace App\Support\ChannelTypes;

use App\Models\NotificationChannel;
use App\Support\ChannelTypes\Concerns\IsGuardedChatServer;
use App\Support\ChannelTypes\Concerns\IsMessagingChannel;
use App\Support\ChannelTypes\Contracts\MessagingChannelType;
use App\Support\Delivery\ChatServerAddress;
use Closure;
use Filament\Forms\Components\TextInput;
use InvalidArgumentException;

/**
 * Posts to one Matrix room as the subscriber's bot account, via Apprise's `matrix[s]://`
 * plugin with an access token only (never a user and password: that form stores an account
 * password and makes Apprise log in, then register an account when the login fails).
 *
 * The homeserver is vetted by ChatServerGuard (the field's SafeChatServerUrl rule, again in
 * buildDestination() and before every send), and the Apprise URL's authority is rebuilt from
 * the stored server, so the host Apprise connects to is always the guarded one.
 *
 * Checked against Apprise 2.0.0 (requests monkeypatched, no network), for
 * `matrixs://{token}@host/{room}?redirect=no&cto=5&rto=10&discovery=no&e2ee=no&mode=off&image=no&hsreq=no`:
 * - the token with no user is the access token: no `/login`, no `/register`, and no
 *   `.well-known` lookup; the calls are `GET /account/whoami`, `POST /join/{room}` (which also
 *   accepts a pending invite), then `PUT /rooms/{id}/send/m.room.message/...` with a plain
 *   `m.text` body (no key upload, no encryption check). Redirects are off, timeouts (5, 10) s.
 * - `!x:ex.org`, `%21x%3Aex.org`, `#w:ex.org` and `%23w%3Aex.org` all arrive as the decoded
 *   room, re-encoded by Apprise in the join path, so `rawurlencode()` of the room round-trips.
 *   A `%2F` in the room splits it into two targets, and a `@user` target makes Apprise create
 *   a direct-message room, so the room allow-list has no `/`, `@` or `,`.
 * - the token is decoded once from the user part: `rawurlencode()` round-trips its `/ + =`,
 *   while a raw `/` cuts the authority short (Apprise then registered an account on another
 *   host), so the token is always encoded.
 * - `hsreq` defaults to on and appends the bot's own server to an `!id` with no server part,
 *   which breaks room v12 IDs (they have none), so `hsreq=no`.
 * - joining an alias the server does not know (404 / M_NOT_FOUND) makes Apprise create a
 *   private room with that alias; there is no switch for that, so the help text says the room
 *   must already exist.
 */
class MatrixChannelType implements MessagingChannelType
{
    use IsGuardedChatServer;
    use IsMessagingChannel;

    public const HOMESERVER_MESSAGE = 'Enter only the homeserver address, e.g. https://matrix.example.org, with no path.';

    public const TOKEN_MESSAGE = 'Paste the bot account\'s access token; it has only letters, digits and . _ ~ + / = -.';

    public const ROOM_MESSAGE = 'Enter a room ID (!abc123:example.org) or alias (#weather:example.org).';

    private const HOMESERVER_MAX_LENGTH = 300;

    private const TOKEN_MAX_LENGTH = 512;

    private const TOKEN_PATTERN = '#^[A-Za-z0-9._~+/=-]+$#D';

    /** An ID (`!opaque[:server]`) or an alias (`#name:server`), in Apprise's own character sets. */
    private const ROOM_PATTERN = '/^(?:![A-Za-z0-9._=-]+(?::[A-Za-z0-9.-]+)?|#[A-Za-z0-9._=-]+:[A-Za-z0-9.-]+)$/D';

    public function slug(): string
    {
        return 'matrix';
    }

    public function label(): string
    {
        return 'Matrix';
    }

    public function icon(): string
    {
        return 'fab-matrix';
    }

    public function formSchema(): array
    {
        return [
            TextInput::make('homeserver')
                ->label('Homeserver')
                ->placeholder('https://matrix.example.org')
                ->required()
                ->maxLength(self::HOMESERVER_MAX_LENGTH)
                ->rules([
                    $this->shapeRule(fn (string $value): bool => ChatServerAddress::parts($value) !== null, self::HOMESERVER_MESSAGE),
                    $this->safeServerRule(),
                ])
                ->helperText('Your homeserver\'s client address. On matrix.org, enter https://matrix-client.matrix.org. It must be reachable from the internet.'),
            TextInput::make('access_token')
                ->label('Access token')
                ->password()
                ->revealable()
                ->required()
                ->maxLength(self::TOKEN_MAX_LENGTH)
                ->rules([$this->shapeRule(self::isToken(...), self::TOKEN_MESSAGE)])
                ->helperText('Use a separate bot account. Sign in to Element as the bot, then Settings → Help & About → Advanced → Access Token. Anyone with this token can act as the bot, so we keep it encrypted and never show it again.'),
            TextInput::make('room')
                ->label('Room')
                ->placeholder('#weather:example.org')
                ->required()
                ->maxLength(255)
                ->rules([$this->shapeRule(self::isRoom(...), self::ROOM_MESSAGE)])
                ->helperText('Invite the bot account to the room and join it as the bot. Include the :server part of a room ID (!abc:example.org) unless your client shows none; a server-less legacy ID passes our check but the bot may never join it. The room must already exist: an alias that doesn\'t would make a new, empty room. Use an unencrypted room: WxNotify does not encrypt messages, and in an encrypted room they show as unencrypted or may be hidden.'),
        ];
    }

    /**
     * Matrix takes the server in a field of its own, so there is no pasted webhook URL.
     */
    protected function webhookUrlRefusal(string $url): ?string
    {
        return null;
    }

    /**
     * A field rule failing with `$message` unless `$accepts` takes the trimmed value.
     *
     * @param  Closure(string): bool  $accepts
     */
    private function shapeRule(Closure $accepts, string $message): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($accepts, $message): void {
            if (! is_string($value) || ! $accepts(trim($value))) {
                $fail($message);
            }
        };
    }

    public static function isToken(string $token): bool
    {
        return strlen($token) <= self::TOKEN_MAX_LENGTH && preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    public static function isRoom(string $room): bool
    {
        return strlen($room) <= 255 && preg_match(self::ROOM_PATTERN, $room) === 1;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{server: string, token: string, room: string}
     */
    protected function configFromForm(array $data): array
    {
        $homeserver = trim((string) ($data['homeserver'] ?? ''));
        $server = strlen($homeserver) <= self::HOMESERVER_MAX_LENGTH ? ChatServerAddress::parts($homeserver) : null;
        $token = trim((string) ($data['access_token'] ?? ''));
        $room = trim((string) ($data['room'] ?? ''));

        if ($server === null) {
            throw new InvalidArgumentException(self::HOMESERVER_MESSAGE);
        }

        if (! self::isToken($token)) {
            throw new InvalidArgumentException(self::TOKEN_MESSAGE);
        }

        if (! self::isRoom($room)) {
            throw new InvalidArgumentException(self::ROOM_MESSAGE);
        }

        return $this->guardServer(['server' => ChatServerAddress::toUrl($server), 'token' => $token, 'room' => $room]);
    }

    /**
     * @return list<string>
     */
    public function requiredConfigKeys(): array
    {
        return ['server', 'token', 'room'];
    }

    /**
     * `matrix[s]://{token}@{host}[:{port}]/{room}?{guarded query}`, the authority rebuilt from
     * the stored server. There is never a user part, so Apprise never logs in or registers.
     * The authority is empty (which MessagingTransport refuses) when the server is unusable or
     * the stored token or room fails its allow-list: Apprise decodes both, so a tampered `/`,
     * `@user` or `,` would otherwise change what it does.
     *
     * @param  array<string, mixed>  $config
     */
    public function appriseUrl(array $config): string
    {
        $token = (string) ($config['token'] ?? '');
        $room = (string) ($config['room'] ?? '');
        ['scheme' => $scheme, 'authority' => $authority] = $this->chatAuthority($config, 'matrixs', 'matrix', self::isToken($token) && self::isRoom($room));

        return "{$scheme}://".rawurlencode($token)."@{$authority}/".rawurlencode($room).'?'
            .$this->guardedQuery(['discovery' => 'no', 'e2ee' => 'no', 'mode' => 'off', 'image' => 'no', 'hsreq' => 'no']);
    }

    /**
     * `host:port/room`, normalised here because the duplicate check compares the raw string:
     * the server as ChatServerAddress spells it with the port always written, the room's server
     * part in lower case, and an alias wholly in lower case (an ID's opaque part is
     * case-sensitive and kept). Empty when the server is unusable.
     *
     * @param  array<string, mixed>  $config
     */
    public function destinationKey(array $config): string
    {
        $server = ChatServerAddress::parts((string) ($config['server'] ?? ''));

        if ($server === null) {
            return '';
        }

        $room = (string) ($config['room'] ?? '');

        if (str_starts_with($room, '#')) {
            $room = strtolower($room);
        } elseif (str_contains($room, ':')) {
            [$opaque, $roomServer] = explode(':', $room, 2);
            $room = $opaque.':'.strtolower($roomServer);
        }

        return ChatServerAddress::hostAndPort($server).'/'.$room;
    }

    /**
     * The room, which is not a secret (the access token is).
     */
    public function displaySummary(NotificationChannel $record): string
    {
        $room = $record->config['room'] ?? null;

        return is_string($room) && $room !== '' ? $room : '—';
    }

    public function destinationNoun(): string
    {
        return 'room';
    }

    public function destinationLabel(): string
    {
        return 'Matrix room';
    }

    public function failureMessage(): string
    {
        return 'Matrix did not accept the message. Check the homeserver address, the access token and that the bot has joined the room.';
    }

    public function confirmationHint(NotificationChannel $channel): string
    {
        return 'We just posted a 6-digit code to your Matrix room.';
    }

    public function codeNotSentHint(): string
    {
        return 'We couldn\'t post to that room. Check the homeserver address and access token, and that the bot has joined the room.';
    }
}
