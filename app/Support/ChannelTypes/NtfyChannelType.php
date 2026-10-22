<?php

namespace App\Support\ChannelTypes;

use App\Models\NotificationChannel;
use App\Models\User;
use App\Rules\SafeChatServerUrl;
use App\Support\ChannelTypes\Concerns\MasksDestinationSecrets;
use App\Support\ChannelTypes\Concerns\UsesAppriseTransport;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Filament\Forms\Components\TextInput;

class NtfyChannelType implements ChannelType
{
    use MasksDestinationSecrets, UsesAppriseTransport;

    public const SERVER_REFUSED = "This channel's ntfy server is not allowed. Edit the channel to use a different server.";

    public function slug(): string
    {
        return 'ntfy';
    }

    public function label(): string
    {
        return 'ntfy';
    }

    public function icon(): string
    {
        return 'heroicon-o-bell-alert';
    }

    public function formSchema(): array
    {
        return [
            TextInput::make('topic')
                ->label('Topic')
                ->required()
                ->alphaDash()
                ->helperText('A short, hard-to-guess name — anyone who knows it can subscribe. E.g. "wxnotify-a1b2c3".'),
            TextInput::make('server')
                ->label('Server (optional)')
                ->url()
                ->rules([new SafeChatServerUrl])
                ->helperText('Leave blank to use the public ntfy.sh server, or enter your own https:// server address. Private and local network addresses are not allowed.'),
        ];
    }

    public function appSetupFields(User $user): ?array
    {
        return [
            new ChannelSetupField('topic', 'Topic', help: 'A short, hard-to-guess name — anyone who knows it can subscribe. E.g. "wxnotify-a1b2c3".', rules: ['alpha_dash', 'max:255']),
            new ChannelSetupField('server', 'Server (optional)', ChannelSetupField::URL, required: false, help: 'Leave blank to use the public ntfy.sh server, or enter your own https:// server address. Private and local network addresses are not allowed.', rules: ['url', 'max:2048', new SafeChatServerUrl]),
        ];
    }

    public function buildAppriseUrl(array $data): string
    {
        $topic = rawurlencode($data['topic']);

        if (filled($data['server'] ?? null)) {
            $host = preg_replace('#^https?://#', '', rtrim($data['server'], '/'));

            return str_starts_with($data['server'], 'https://')
                ? "ntfys://{$host}/{$topic}"
                : "ntfy://{$host}/{$topic}";
        }

        return "ntfy://{$topic}";
    }

    /**
     * The custom server a stored ntfy Apprise URL connects to (`ntfys://host[:port]/topic` is
     * https, `ntfy://host[:port]/topic` http), for the send-time guard; null for the default
     * server (`ntfy://topic`) and for any other URL.
     */
    public static function serverUrlFrom(string $appriseUrl): ?string
    {
        if (preg_match('#^(ntfys?)://([^/]+)/#', $appriseUrl, $matches) !== 1) {
            return null;
        }

        return ($matches[1] === 'ntfys' ? 'https' : 'http').'://'.$matches[2];
    }

    public function supportsRichContent(): bool
    {
        return false;
    }

    public function countsTowardFreeSlot(): bool
    {
        return true;
    }

    /**
     * The topic is masked, not shown in full: per its own helper text above,
     * knowing it is enough to subscribe, so it functions as a shared secret.
     */
    public function displaySummary(NotificationChannel $record): string
    {
        if (! preg_match('#^ntfys?://(?:([^/]+)/)?([\w-]+)$#', (string) $record->apprise_url, $matches)) {
            return '—';
        }

        $host = $matches[1] ?? '';
        $masked = self::maskLast4($matches[2]);

        return $host === '' ? $masked : "{$host} {$masked}";
    }

    public function destinationLabel(): string
    {
        return 'ntfy server and topic';
    }
}
