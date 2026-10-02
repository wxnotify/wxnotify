<?php

namespace App\Support\ChannelTypes;

use App\Models\NotificationChannel;
use App\Support\ChannelTypes\Concerns\UsesAppriseTransport;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Filament\Forms\Components\TextInput;

class TelegramChannelType implements ChannelType
{
    use UsesAppriseTransport;

    public function slug(): string
    {
        return 'telegram';
    }

    public function label(): string
    {
        return 'Telegram';
    }

    public function icon(): string
    {
        return 'fab-telegram';
    }

    public function formSchema(): array
    {
        return [
            TextInput::make('bot_token')
                ->label('Bot token')
                ->required()
                ->helperText('Create a bot with @BotFather on Telegram to get a token.'),
            TextInput::make('chat_id')
                ->label('Chat ID')
                ->required()
                ->helperText('Message your bot, then visit api.telegram.org/bot<token>/getUpdates to find your chat ID.'),
        ];
    }

    public function buildAppriseUrl(array $data): string
    {
        return 'tgram://'.rawurlencode($data['bot_token']).'/'.rawurlencode($data['chat_id']);
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
     * The chat ID isn't secret (the bot token is what actually grants send
     * access), so it's shown in full rather than masked.
     */
    public function displaySummary(NotificationChannel $record): string
    {
        if (! preg_match('#^tgram://.+/([^/]+)$#', (string) $record->apprise_url, $matches)) {
            return '—';
        }

        return 'Chat '.rawurldecode($matches[1]);
    }
}
