<?php

namespace App\Filament\Dashboard\Pages\Auth;

use App\Enums\UnitSystem;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Schema;

class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                ToggleButtons::make('unit_system')
                    ->label('Units')
                    ->options(collect(UnitSystem::cases())->mapWithKeys(fn (UnitSystem $system): array => [$system->value => $system->label()])->all())
                    ->inline()
                    ->required()
                    ->helperText('Temperatures, wind, distances and pressure in your emails, alerts and alert forms.'),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }
}
