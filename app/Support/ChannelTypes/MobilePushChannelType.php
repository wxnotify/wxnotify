<?php

namespace App\Support\ChannelTypes;

use App\Enums\ChannelTransport;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\Concerns\NotCreatableInApp;
use App\Support\ChannelTypes\Contracts\ChannelType;
use LogicException;

/**
 * A phone signed in to the WxNotify app. These channels are created by the app's registration
 * endpoint (one per signed-in device), never by hand, so the type is hidden from the picker and
 * has no setup form. The stored config is the FCM token and the platform; only the platform is
 * ever shown.
 */
class MobilePushChannelType implements ChannelType
{
    use NotCreatableInApp;

    public function slug(): string
    {
        return 'mobile_push';
    }

    public function label(): string
    {
        return 'Mobile app';
    }

    public function icon(): string
    {
        return 'heroicon-o-device-phone-mobile';
    }

    public function formSchema(): array
    {
        return [];
    }

    public function transport(): ChannelTransport
    {
        return ChannelTransport::MobilePush;
    }

    public function buildDestination(array $data): array
    {
        throw new LogicException('Mobile app channels are created by the app, not by hand.');
    }

    public function supportsInlineCreate(): bool
    {
        return false;
    }

    /**
     * Never offered in the "add a channel" picker: a phone appears when the app registers it.
     */
    public function isAvailable(): bool
    {
        return false;
    }

    public function supportsRichContent(): bool
    {
        return false;
    }

    /**
     * App-created, so it can't be blocked by (or use up) Free's single no-cost channel slot.
     */
    public function countsTowardFreeSlot(): bool
    {
        return false;
    }

    public function displaySummary(NotificationChannel $record): string
    {
        return match ($record->config['platform'] ?? null) {
            'android' => 'Android device',
            'ios' => 'iPhone',
            default => '—',
        };
    }

    public function destinationLabel(): string
    {
        return 'Mobile device';
    }
}
