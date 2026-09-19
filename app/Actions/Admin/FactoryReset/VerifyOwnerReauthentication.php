<?php

namespace App\Actions\Admin\FactoryReset;

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Support\Facades\Hash;

class VerifyOwnerReauthentication
{
    public function __invoke(User $owner, string $password, ?string $authenticatorCode): bool
    {
        if (! Hash::check($password, $owner->password)) {
            return false;
        }

        $appAuthentication = AppAuthentication::make();

        if (! $appAuthentication->isEnabled($owner)) {
            return true;
        }

        return filled($authenticatorCode) && $appAuthentication->verifyCode($authenticatorCode);
    }
}
