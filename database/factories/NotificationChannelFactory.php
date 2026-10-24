<?php

namespace Database\Factories;

use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\PhoneConsent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationChannel>
 */
class NotificationChannelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'channel_type' => 'discord',
            'label' => fake()->word(),
            'apprise_url' => 'discord://123456789/token/',
            'verified_at' => now(),
        ];
    }

    public function webhook(string $url = 'https://hooks.example.com/wx', string $secret = 'test-secret'): static
    {
        return $this->state(fn () => [
            'channel_type' => 'webhook',
            'apprise_url' => null,
            'config' => ['url' => $url, 'secret' => $secret],
        ]);
    }

    public function webPush(): static
    {
        return $this->state(fn () => ['channel_type' => 'webpush', 'apprise_url' => null, 'config' => [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'public_key' => str_repeat('B', 87),
            'auth_token' => str_repeat('a', 22),
            'content_encoding' => 'aes128gcm',
        ]]);
    }

    public function mobilePush(?string $token = null, string $platform = 'android'): static
    {
        $token ??= 'fcm-token-'.fake()->unique()->uuid();

        return $this->state(fn () => [
            'channel_type' => 'mobile_push',
            'label' => 'Pixel 9',
            'apprise_url' => null,
            'config' => ['fcm_token' => $token, 'platform' => $platform],
            'device_token_hash' => hash('sha256', $token),
        ]);
    }

    public function email(string $to = 'subscriber@example.com'): static
    {
        return $this->state(fn () => ['channel_type' => 'email', 'apprise_url' => null, 'config' => ['to' => $to]]);
    }

    /**
     * A Twilio SMS channel texting the given E.164 number, with valid-shaped credentials and a consent record.
     */
    public function twilioSms(string $to = '+15555550123'): static
    {
        return $this->state(fn () => [
            'channel_type' => 'twilio_sms',
            'label' => 'Twilio SMS •••• '.substr($to, -4),
            'apprise_url' => null,
            'config' => [
                'account_sid' => 'AC'.str_repeat('0a', 16),
                'auth_token' => str_repeat('1b', 16),
                'from' => '+15552340001',
                'to' => $to,
                'consent_version' => PhoneConsent::VERSION,
                'consented_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * A Twilio voice channel calling the given E.164 number, with a consent record.
     */
    public function twilioVoice(string $to = '+15555550123'): static
    {
        return $this->state(fn () => [
            'channel_type' => 'twilio_voice',
            'label' => 'Twilio voice call •••• '.substr($to, -4),
            'apprise_url' => null,
            'config' => [
                'account_sid' => 'AC'.str_repeat('0a', 16),
                'auth_token' => str_repeat('1b', 16),
                'from' => '+15552340001',
                'to' => $to,
                'consent_version' => PhoneConsent::VERSION,
                'consented_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * A BulkVS SMS channel texting the given E.164 number, with a consent record.
     */
    public function bulkVsSms(string $to = '+15555550123'): static
    {
        return $this->state(fn () => [
            'channel_type' => 'bulkvs_sms',
            'label' => 'BulkVS SMS •••• '.substr($to, -4),
            'apprise_url' => null,
            'config' => [
                'api_username' => 'ops@example.com',
                'api_password' => 'Secr3t-pass',
                'from' => '+15552340001',
                'to' => $to,
                'consent_version' => PhoneConsent::VERSION,
                'consented_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * An httpSMS channel texting the given E.164 number, with a consent record.
     */
    public function httpSms(string $to = '+15555550123'): static
    {
        return $this->state(fn () => [
            'channel_type' => 'httpsms',
            'label' => 'httpSMS (your Android phone) •••• '.substr($to, -4),
            'apprise_url' => null,
            'config' => [
                'api_key' => 'uk-Test_key9',
                'from' => '+15552340001',
                'to' => $to,
                'consent_version' => PhoneConsent::VERSION,
                'consented_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * A Google Chat channel posting to a space through its webhook (space, key and decoded token).
     */
    public function googleChat(string $space = 'AAAAbCdEf12'): static
    {
        return $this->state(fn () => [
            'channel_type' => 'google_chat',
            'label' => 'Google Chat',
            'apprise_url' => null,
            'config' => [
                'space' => $space,
                'key' => 'AIzaSyDdI0hCZtE6vySjMm-WEfRq3CPzqKqqsHI',
                'token' => 'Zq3x-_Vb9Kd2LmNoPq7RsTuVwXyZ0aBcDeFgHiJkLmN=',
            ],
        ]);
    }

    /**
     * A Mattermost channel posting through an incoming webhook on the subscriber's server
     * (normalised server, sub-path and token). Sends need the host to resolve publicly:
     * chanFakeChatDns(['chat.example.com' => [...]]).
     */
    public function mattermost(string $server = 'https://chat.example.com', string $path = '', string $token = 'h7kq3m9xw5e8rbt4y6uzi3o5pa'): static
    {
        return $this->state(fn () => [
            'channel_type' => 'mattermost',
            'label' => 'Mattermost',
            'apprise_url' => null,
            'config' => ['server' => $server, 'path' => $path, 'token' => $token],
        ]);
    }

    /**
     * A Rocket.Chat channel posting through an incoming webhook on the subscriber's server.
     * Sends need the host to resolve publicly: chanFakeChatDns(['chat.example.com' => [...]]).
     */
    public function rocketChat(string $server = 'https://chat.example.com', string $webhookId = 'aB3dE5fG7hJ9kL2mN', string $webhookToken = 'Zx9Cv8Bn7Mq6Wr5Ty4Ui3Op2As1Df0Gh'): static
    {
        return $this->state(fn () => [
            'channel_type' => 'rocketchat',
            'label' => 'Rocket.Chat',
            'apprise_url' => null,
            'config' => ['server' => $server, 'webhook_id' => $webhookId, 'webhook_token' => $webhookToken],
        ]);
    }

    /**
     * A Matrix channel posting to one room as the subscriber's bot (access token only).
     * Sends need the host to resolve publicly: chanFakeChatDns(['matrix.example.org' => [...]]).
     */
    public function matrix(string $server = 'https://matrix.example.org', string $token = 'syt_d3hub3RpZnk_AbCdEfGhIjKlMnOpQrSt_1a2B3c', string $room = '!AbCdEfGhIjKlMn:example.org'): static
    {
        return $this->state(fn () => [
            'channel_type' => 'matrix',
            'label' => 'Matrix',
            'apprise_url' => null,
            'config' => ['server' => $server, 'token' => $token, 'room' => $room],
        ]);
    }

    /**
     * A WhatsApp channel sending the subscriber's approved template to the given E.164 number,
     * with valid-shaped Meta credentials and a consent record.
     */
    public function whatsApp(string $to = '+525512345678'): static
    {
        return $this->state(fn () => [
            'channel_type' => 'whatsapp',
            'label' => 'WhatsApp •••• '.substr($to, -4),
            'apprise_url' => null,
            'config' => [
                'access_token' => 'EAABsbCS1iHgBO7ZBx9Kd2LmNoPq7RsTuVwXyZ0aBcDeFgHiJk',
                'phone_number_id' => '123456789012',
                'template' => 'wx_alert',
                'template_language' => 'es_MX',
                'to' => $to,
                'consent_version' => PhoneConsent::VERSION,
                'consented_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * A PagerDuty channel opening incidents through an Events API v2 integration key, with the
     * default source and component.
     */
    public function pagerDuty(string $integrationKey = 'e93facc04764012d7bfb002500d5d1a6', string $severity = 'error'): static
    {
        return $this->state(fn () => [
            'channel_type' => 'pagerduty',
            'label' => 'PagerDuty',
            'apprise_url' => null,
            'config' => [
                'integration_key' => $integrationKey,
                'severity' => $severity,
                'source' => 'WxNotify',
                'component' => 'weather-alert',
            ],
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => [
            'verified_at' => null,
            'confirmation_code' => '123456',
            'confirmation_code_expires_at' => now()->addMinutes(15),
        ]);
    }
}
