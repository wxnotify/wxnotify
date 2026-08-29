<?php

namespace App\Actions\Support;

use App\Models\User;

class GenerateChatwootIdentityHash
{
    public function __invoke(User $user): string
    {
        return hash_hmac(
            'sha256',
            (string) $user->getKey(),
            (string) config('services.chatwoot.identity_secret'),
        );
    }
}
