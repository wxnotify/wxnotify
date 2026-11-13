<?php

namespace App\Filament\Dashboard\Pages\Auth;

use Coderflex\FilamentTurnstile\Forms\Components\Turnstile;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class Register extends BaseRegister
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent()
                    ->rule(Password::min(8)->letters()->mixedCase()->numbers()->symbols())
                    ->extraInputAttributes(['data-password-input' => true]),
                View::make('filament.dashboard.password-requirements'),
                $this->getPasswordConfirmationFormComponent(),
                ...$this->captchaComponents(),
            ]);
    }

    /**
     * The sign-up captcha. A self-hosted install that has no Cloudflare Turnstile keys signs people up
     * without one. It is skipped only when BOTH keys are blank: with either key set the check applies, so a
     * half-configured install fails loudly instead of silently running without bot protection.
     *
     * @return array<int, Turnstile>
     */
    private function captchaComponents(): array
    {
        $configured = filled(config('turnstile.turnstile_site_key')) || filled(config('turnstile.turnstile_secret_key'));

        return $configured ? [Turnstile::make('turnstile_token')] : [];
    }
}
