<?php

namespace App\Enums;

/**
 * The SPC convective outlook scale, in rising order of risk.
 */
enum OutlookCategory: string
{
    case None = 'none';
    case Thunderstorm = 'thunderstorm';
    case Marginal = 'marginal';
    case Slight = 'slight';
    case Enhanced = 'enhanced';
    case Moderate = 'moderate';
    case High = 'high';

    public function emoji(): string
    {
        return match ($this) {
            self::None => '⚪',
            self::Thunderstorm => '🟤',
            self::Marginal => '🟢',
            self::Slight => '🟡',
            self::Enhanced => '🟠',
            self::Moderate => '🔴',
            self::High => '🟣',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Thunderstorm => 'General thunderstorms',
            self::Marginal => 'Marginal',
            self::Slight => 'Slight',
            self::Enhanced => 'Enhanced',
            self::Moderate => 'Moderate',
            self::High => 'High',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Thunderstorm => 1,
            self::Marginal => 2,
            self::Slight => 3,
            self::Enhanced => 4,
            self::Moderate => 5,
            self::High => 6,
        };
    }

    /**
     * An SPC `LABEL` value (TSTM, MRGL, SLGT, ENH, MDT, HIGH); anything else is None.
     */
    public static function fromLabel(string $label): self
    {
        return match ($label) {
            'TSTM' => self::Thunderstorm,
            'MRGL' => self::Marginal,
            'SLGT' => self::Slight,
            'ENH' => self::Enhanced,
            'MDT' => self::Moderate,
            'HIGH' => self::High,
            default => self::None,
        };
    }

    /**
     * Days 4-8 have no categories: a 15% area counts as Slight, 30% or more as Enhanced.
     */
    public static function fromProbability(float $probability): self
    {
        return match (true) {
            $probability >= 0.30 => self::Enhanced,
            $probability >= 0.15 => self::Slight,
            default => self::None,
        };
    }
}
