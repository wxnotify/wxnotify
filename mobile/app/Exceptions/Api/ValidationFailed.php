<?php

namespace App\Exceptions\Api;

/**
 * The server refused the details (422). `errors` maps each field to its first message.
 */
final class ValidationFailed extends ApiException
{
    /**
     * @param  array<string, string>  $errors
     */
    public function __construct(public readonly array $errors, string $message = '')
    {
        parent::__construct($message);
    }
}
