<?php

namespace App\Support;

/**
 * The groups of events the Live map reads by VTEC code: the short-fuse warnings (drawn and listed
 * by default) and the SPC Tornado and Severe Thunderstorm Watches (a layer of their own). Codes are
 * stored upper-case on active_wwas.
 */
class LiveWarningKinds
{
    public const SHORT_FUSE = 'short_fuse';

    public const WATCHES = 'watches';

    /**
     * @var array<string, array{phenomena: list<string>, significance: string}>
     */
    private const KINDS = [
        self::SHORT_FUSE => ['phenomena' => ['TO', 'SV', 'FF', 'MA', 'EW', 'SQ', 'DS'], 'significance' => 'W'],
        self::WATCHES => ['phenomena' => ['TO', 'SV'], 'significance' => 'A'],
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::KINDS);
    }

    public static function isKnown(string $kind): bool
    {
        return isset(self::KINDS[$kind]);
    }

    /**
     * @return list<string>
     */
    public static function phenomena(string $kind): array
    {
        return self::KINDS[$kind]['phenomena'];
    }

    public static function significance(string $kind): string
    {
        return self::KINDS[$kind]['significance'];
    }

    /**
     * The event names of a kind, in its order, for the filter chips.
     *
     * @return list<string>
     */
    public static function eventNames(string $kind): array
    {
        $significance = self::significance($kind);

        return array_map(
            fn (string $phenomenon): string => (string) VtecEvent::name($phenomenon, $significance),
            self::phenomena($kind),
        );
    }
}
