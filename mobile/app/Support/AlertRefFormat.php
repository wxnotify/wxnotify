<?php

namespace App\Support;

/**
 * The alert reference a push's tap link carries (wxnotify://product/{link}/{ref}, wxnotify://alert/{ref}): a copy of
 * the server's App\Support\AlertRef format. Any app or page on the phone can open a custom-scheme link, so anything
 * else is ignored before it reaches the server.
 */
final class AlertRefFormat
{
    public const int MAX_LENGTH = 40;

    public const string PATTERN = '/^(?:p\.(0|[1-9][0-9]{0,17})\.([1-9][0-9]{0,17})|b\.([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}))$/D';

    public static function isValid(mixed $ref): bool
    {
        return is_string($ref) && strlen($ref) <= self::MAX_LENGTH && preg_match(self::PATTERN, $ref) === 1;
    }
}
