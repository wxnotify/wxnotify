<?php

namespace App\Support\Filament;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Happenv\FilamentPasskeys\Contracts\HasPasskeysAuthentication;
use SensitiveParameter;

/**
 * App authentication whose recovery codes are shared with the passkey factor (see
 * RecoverablePasskeyAuthentication). Turning the app off must not delete codes a passkey still relies on.
 */
class RecoverableAppAuthentication extends AppAuthentication
{
    public function saveRecoveryCodes(HasAppAuthenticationRecovery $user, #[SensitiveParameter] ?array $codes): void
    {
        if ($codes === null && $user instanceof HasPasskeysAuthentication && $user->hasPasskeysEnabled()) {
            return;
        }

        parent::saveRecoveryCodes($user, $codes);
    }
}
