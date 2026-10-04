<?php

namespace App\Support\Concerns;

use App\Support\TokenStore;

trait RequiresSignIn
{
    /**
     * Sends a signed-out visitor to Sign in; returns true when it did, so mount() can stop.
     */
    protected function redirectIfSignedOut(): bool
    {
        if (app(TokenStore::class)->token() !== null) {
            return false;
        }

        $this->replace('/sign-in');

        return true;
    }
}
