<?php

namespace App\Enums;

enum Tier: string
{
    case Free = 'free';
    case Plus = 'plus';
    case Pro = 'pro';

    /**
     * Whether this tier is at least the given one (Free < Plus < Pro).
     */
    public function meets(Tier $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Free => 0,
            self::Plus => 1,
            self::Pro => 2,
        };
    }
}
