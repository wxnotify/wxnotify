<?php

namespace App\Support\Delivery;

/**
 * The outcome of one FCM send. The error is always a fixed string plus the numeric HTTP status:
 * never a response body, an exception message, the device token or a credential.
 */
final class FcmResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $error = null,
        public readonly bool $tokenInvalid = false,
    ) {}

    public static function delivered(): self
    {
        return new self(true);
    }

    public static function failed(string $error): self
    {
        return new self(false, $error);
    }

    /**
     * FCM says the device token will never work again (app uninstalled, token rotated away).
     */
    public static function unregistered(string $error): self
    {
        return new self(false, $error, tokenInvalid: true);
    }
}
