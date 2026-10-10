<?php

namespace App\Exceptions\Api;

/**
 * The server refused the change because of the current state (409): a location still used by
 * alerts, or one that follows a phone. `reason` is the server's error code, `getMessage()` its text.
 */
final class Conflict extends ApiException
{
    /**
     * @param  list<string>  $alerts
     */
    public function __construct(public readonly string $reason, string $message = '', public readonly array $alerts = [])
    {
        parent::__construct($message);
    }
}
