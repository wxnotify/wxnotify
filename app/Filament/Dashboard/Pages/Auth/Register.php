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
                Turnstile::make('turnstile_token'),
            ]);
    }
}
