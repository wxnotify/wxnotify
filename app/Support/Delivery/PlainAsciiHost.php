<?php

namespace App\Support\Delivery;

/**
 * The one definition of which URL hosts the outbound-URL guards accept at all.
 *
 * curl converts international host names (including fullwidth dots such as
 * `127。0。0。1`) and percent-decodes them, so a guard that judges the host as
 * written would look up, classify and allow a different name than the one curl
 * then connects to. Refusing everything but plain ASCII host syntax before any
 * lookup closes that gap; international names must be written in their `xn--` form.
 */
final class PlainAsciiHost
{
    public const MESSAGE = 'Enter the server host name in plain ASCII (use the xn-- form for international names).';

    /** Letters, digits, dot, hyphen, underscore, and the colon and brackets of an IPv6 literal. */
    public static function accepts(string $host): bool
    {
        return preg_match('/[^A-Za-z0-9.\-_:\[\]]/', $host) !== 1;
    }

    /** The refusal message when the host is not plain ASCII, else null. */
    public static function violation(string $host): ?string
    {
        return self::accepts($host) ? null : self::MESSAGE;
    }
}
