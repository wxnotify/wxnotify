<?php

namespace App\Support\ChannelTypes\Concerns;

use App\Models\NotificationChannel;
use App\Rules\SafeChatServerUrl;
use App\Support\Delivery\ChatServerAddress;
use App\Support\Delivery\ChatServerGuard;
use Closure;
use Filament\Forms\Components\TextInput;
use InvalidArgumentException;

/**
 * What the messaging types that post to a subscriber-supplied chat server (Mattermost,
 * Rocket.Chat, Matrix) share: the pasted-URL field with its server rule (Matrix, which takes
 * the server in a field of its own, uses the rule alone), the guard run again at
 * save, the Apprise scheme and authority taken from the stored server, and the server-only
 * `serverUrl()` and summary. The URL shape, the stored values and the destination key stay in
 * each type. Expects a `config['server']` spelled by ChatServerAddress.
 */
trait IsGuardedChatServer
{
    /**
     * Why the pasted URL is not this type's webhook shape, or null when it is.
     */
    abstract protected function webhookUrlRefusal(string $url): ?string;

    /**
     * The pasted-URL field: this type's shape rule, then SafeChatServerUrl on the trimmed
     * value (the guard refuses any whitespace, so an untrimmed paste would get a misleading
     * message).
     */
    protected function webhookUrlField(string $helperText): TextInput
    {
        return TextInput::make('webhook_url')
            ->label('Webhook URL')
            ->password()
            ->revealable()
            ->required()
            ->maxLength(2048)
            ->rules([
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    $refusal = is_string($value) ? $this->webhookUrlRefusal($value) : $this->webhookUrlRefusal('');

                    if ($refusal !== null) {
                        $fail($refusal);
                    }
                },
                $this->safeServerRule(),
            ])
            ->helperText($helperText);
    }

    /**
     * SafeChatServerUrl on the trimmed value, as a field rule.
     */
    protected function safeServerRule(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            (new SafeChatServerUrl)->validate($attribute, is_string($value) ? trim($value) : $value, $fail);
        };
    }

    /**
     * Runs ChatServerGuard on a parsed webhook's server and returns the parsed values.
     *
     * @template T of array{server: string}
     *
     * @param  T  $parsed
     * @return T
     *
     * @throws InvalidArgumentException with the guard's fixed message
     */
    protected function guardServer(array $parsed): array
    {
        $violation = app(ChatServerGuard::class)->violation($parsed['server']);

        if ($violation !== null) {
            throw new InvalidArgumentException($violation);
        }

        return $parsed;
    }

    /**
     * The Apprise scheme and the `host[:port]` authority, both from the stored server so the
     * host Apprise connects to is the guarded one. The authority is empty when the server is
     * unusable or `$wellFormed` is false (a stored value outside what the parser allows), which
     * MessagingTransport refuses.
     *
     * @param  array<string, mixed>  $config
     * @return array{scheme: string, authority: string}
     */
    protected function chatAuthority(array $config, string $tlsScheme, string $plainScheme, bool $wellFormed = true): array
    {
        $server = ChatServerAddress::parts((string) ($config['server'] ?? ''));

        return [
            'scheme' => ($server['scheme'] ?? 'https') === 'https' ? $tlsScheme : $plainScheme,
            'authority' => $server === null || ! $wellFormed ? '' : ChatServerAddress::authority($server),
        ];
    }

    /**
     * The stored server, or null when there is none (the transport's required-key check refuses
     * such a channel first).
     *
     * @param  array<string, mixed>  $config
     */
    public function serverUrl(array $config): ?string
    {
        $server = $config['server'] ?? null;

        return is_string($server) && $server !== '' ? $server : null;
    }

    /**
     * The server's host, which is not a secret (the webhook is).
     */
    public function displaySummary(NotificationChannel $record): string
    {
        $server = ChatServerAddress::parts((string) ($record->config['server'] ?? ''));

        return $server['host'] ?? '—';
    }
}
