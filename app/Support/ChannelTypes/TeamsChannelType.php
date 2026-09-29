<?php

namespace App\Support\ChannelTypes;

use App\Models\NotificationChannel;
use App\Support\ChannelTypes\Concerns\MasksDestinationSecrets;
use App\Support\ChannelTypes\Concerns\UsesAppriseTransport;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Closure;
use Filament\Forms\Components\TextInput;

class TeamsChannelType implements ChannelType
{
    use MasksDestinationSecrets, UsesAppriseTransport;

    public function slug(): string
    {
        return 'msteams';
    }

    public function label(): string
    {
        return 'Microsoft Teams';
    }

    public function icon(): string
    {
        return 'fab-microsoft';
    }

    public function formSchema(): array
    {
        return [
            TextInput::make('webhook_url')
                ->label('Workflows webhook URL')
                ->url()
                ->required()
                ->helperText('In Teams: Workflows → "Post to a channel when a webhook request is received", then copy the HTTP POST URL. Microsoft retired the old Office 365 connector webhooks, so those URLs no longer work.')
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (self::parseWorkflowUrl((string) $value) === null) {
                        $fail('That is not a Power Automate Workflows webhook URL (https://…logic.azure.com/workflows/…&sig=…).');
                    }
                }),
        ];
    }

    /**
     * @return ?array{host: string, workflow: string, signature: string}
     */
    public static function parseWorkflowUrl(string $url): ?array
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])) {
            return null;
        }

        if (! str_ends_with(strtolower($parts['host']), '.logic.azure.com')) {
            return null;
        }

        if (! preg_match('#/workflows/([0-9a-f]{32})/#i', $parts['path'] ?? '', $path)) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);
        $signature = $query['sig'] ?? null;

        if (! is_string($signature) || ! preg_match('/^[A-Za-z0-9_-]+$/', $signature)) {
            return null;
        }

        $port = $parts['port'] ?? 443;

        return [
            'host' => strtolower($parts['host']).($port === 443 ? '' : ":{$port}"),
            'workflow' => strtolower($path[1]),
            'signature' => $signature,
        ];
    }

    public function buildAppriseUrl(array $data): string
    {
        $parsed = self::parseWorkflowUrl($data['webhook_url']);

        return "workflows://{$parsed['host']}/{$parsed['workflow']}/{$parsed['signature']}";
    }

    public function countsTowardFreeSlot(): bool
    {
        return true;
    }

    public function displaySummary(NotificationChannel $record): string
    {
        if (! preg_match('#^workflows://[^/]+/[0-9a-f]{32}/(.+)$#i', (string) $record->apprise_url, $matches)) {
            return '—';
        }

        return self::maskLast4($matches[1]);
    }
}
