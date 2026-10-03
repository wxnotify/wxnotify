<?php

namespace App\Filament\Dashboard\Pages\Auth;

use App\Enums\UnitSystem;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Schema;
use Tapp\FilamentTimezoneField\Forms\Components\TimezoneSelect;

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
                TimezoneSelect::make('timezone')
                    ->label('Timezone')
                    ->searchable()
                    ->getTimezoneFromBrowser()
                    ->helperText('Used when a location has no timezone of its own (such as a mobile location), for the day boundaries on your dashboard charts, and as the starting value for new locations. A location\'s own timezone always takes priority. Clear it to stop using one.'),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }
}
