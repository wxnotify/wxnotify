<?php

namespace App\Support;

use GuzzleHttp\Exception\ConnectException;
use Throwable;

/**
 * Turns a failed Traccar request into a short reason a subscriber can act on
 * ("the server rejected the API token", not just "unreachable or returned an error").
 *
 * Every message is a fixed string plus, at most, the numeric HTTP status: the
 * server URL, the exception text and the response body are never repeated,
 * because the URL is subscriber-supplied and the exception text can carry it
 * and the Bearer token.
 */
final class TraccarFailure
{
    public const MALFORMED = 'The server sent a response WxNotify could not understand.';

    public const UNREACHABLE = 'Could not connect to the server.';

    public static function forStatus(int $status): string
    {
        return match (true) {
            $status === 401, $status === 403 => "The server rejected the API token (HTTP {$status}). Check that the token is still valid.",
            $status === 404 => 'The server answered HTTP 404. Check the server URL.',
            $status === 429 => 'The server is rate limiting requests (HTTP 429). Polling will retry.',
            $status >= 500 => "The Traccar server reported an error (HTTP {$status}).",
            $status >= 300 && $status < 400 => "The server redirected the request (HTTP {$status}). Use the server's final URL.",
            default => "The server answered HTTP {$status}.",
        };
    }

    /** From the curl error number Guzzle keeps on a connect failure, when there is one. */
    public static function forException(Throwable $e): string
    {
        $previous = $e->getPrevious();
        $errno = $previous instanceof ConnectException ? ($previous->getHandlerContext()['errno'] ?? null) : null;

        return match ($errno) {
            6 => "The server's host name could not be found.",
            7 => 'The server refused the connection. Check the host and port.',
            28 => 'The server took too long to respond.',
            35, 51, 58, 60 => "Could not make a secure (HTTPS) connection. Check the server's certificate.",
            default => self::UNREACHABLE,
        };
    }
}
