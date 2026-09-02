<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Enums\Tier;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->disabled(),
                TextInput::make('email')
                    ->disabled(),
                Toggle::make('is_staff'),
                Select::make('tier_override')
                    ->label('Tier override')
                    ->options(collect(Tier::cases())->mapWithKeys(fn (Tier $tier) => [$tier->value => ucfirst($tier->value)]))
                    ->nullable()
                    ->live(),
                Textarea::make('tier_override_reason')
                    ->label('Reason')
                    ->requiredWith('tier_override')
                    ->helperText('Required when setting a tier override — why is this subscriber being gifted or downgraded?'),
                Section::make('Change Password')
                    ->description('Leave both fields blank to keep the current password.')
                    ->schema([
                        self::passwordField(),
                        self::passwordConfirmationField(),
                    ]),
            ]);
    }

    /**
     * Optional — blank means "don't change the password" (dehydrated() drops
     * it from the saved data entirely rather than saving an empty value).
     * Same rule/dehydration shape as the dashboard's EditProfile password
     * field: no live character-class checklist, just Password::default().
     */
    protected static function passwordField(): TextInput
    {
        return TextInput::make('password')
            ->password()
            ->revealable()
            ->rule(Password::default())
            ->autocomplete('new-password')
            ->same('passwordConfirmation')
            ->dehydrated(fn (?string $state): bool => filled($state))
            ->dehydrateStateUsing(fn (?string $state): string => Hash::make($state));
    }

    protected static function passwordConfirmationField(): TextInput
    {
        return TextInput::make('passwordConfirmation')
            ->label('Confirm password')
            ->password()
            ->revealable()
            ->autocomplete('new-password')
            ->dehydrated(false);
    }
}
