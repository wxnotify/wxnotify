<?php

namespace App\Actions\Account;

use App\Enums\SignUpRefusal;
use RuntimeException;

/**
 * Thrown by SignUpGate::admit() when the person may not create an account.
 */
class SignUpRefused extends RuntimeException
{
    public function __construct(public readonly SignUpRefusal $refusal)
    {
        parent::__construct($refusal->message());
    }
}
