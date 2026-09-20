<?php

namespace Database\Factories;

use App\Models\NotificationChannel;
use App\Models\User;
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

    public function unverified(): static
    {
        return $this->state(fn () => [
            'verified_at' => null,
            'confirmation_code' => '123456',
            'confirmation_code_expires_at' => now()->addMinutes(15),
        ]);
    }
}
