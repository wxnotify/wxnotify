<?php

namespace Database\Factories;

use App\Models\InviteCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InviteCode>
 */
class InviteCodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => InviteCode::generateCode(),
            'note' => null,
            'max_uses' => 1,
            'used_count' => 0,
            'expires_at' => null,
            'email' => null,
            'revoked_at' => null,
            'created_by' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()->subHour()]);
    }

    public function usedUp(): static
    {
        return $this->state(fn (): array => ['max_uses' => 1, 'used_count' => 1]);
    }

    public function forEmail(string $email): static
    {
        return $this->state(fn (): array => ['email' => mb_strtolower($email)]);
    }

    public function shared(int $maxUses): static
    {
        return $this->state(fn (): array => ['max_uses' => $maxUses, 'used_count' => 0]);
    }
}
