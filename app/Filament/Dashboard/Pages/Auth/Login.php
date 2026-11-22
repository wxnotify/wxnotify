<?php

namespace App\Filament\Dashboard\Pages\Auth;

use App\Actions\Account\SignUpGate;
use App\Enums\SignUpPolicy;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Support\Htmlable;

class Login extends BaseLogin
{
    /**
     * Hides the "Register" link while sign-ups are closed.
     */
    #[Unexposed]
    public function getSubheading(): string|Htmlable|null
    {
        if (app(SignUpGate::class)->policy() === SignUpPolicy::Closed) {
            return null;
        }

        return parent::getSubheading();
    }
}
