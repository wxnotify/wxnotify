<?php

namespace App\Support\Delivery;

final class DeliveryResult
{
    public function __construct(public readonly bool $success, public readonly ?string $error = null) {}

    public static function delivered(): self
    {
        return new self(true);
    }

    public static function failed(string $error): self
    {
        return new self(false, $error);
    }
}
