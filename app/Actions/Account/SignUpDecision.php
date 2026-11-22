<?php

namespace App\Actions\Account;

use App\Enums\SignUpRefusal;

/**
 * The gate's answer: allowed, or refused with the reason.
 */
final readonly class SignUpDecision
{
    public bool $allowed;

    public function __construct(public ?SignUpRefusal $refusal = null)
    {
        $this->allowed = $refusal === null;
    }

    public static function allow(): self
    {
        return new self;
    }

    public static function refuse(SignUpRefusal $refusal): self
    {
        return new self($refusal);
    }

    public function message(): ?string
    {
        return $this->refusal?->message();
    }
}
