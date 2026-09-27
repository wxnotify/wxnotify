<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Enums\Tier;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                    ->required()
                    ->disabled(fn (?string $operation): bool => $operation !== 'create'),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->disabled(fn (?string $operation): bool => $operation !== 'create'),
                Toggle::make('is_staff'),
                Toggle::make('is_owner')
                    ->label('Owner')
                    ->helperText('Owners can run Reset to Factory Defaults. Implies staff.')
                    ->disabled(fn (): bool => ! auth()->user()?->is_owner),
                Toggle::make('is_test_account')
                    ->label('Fixture test account')
                    ->helperText('The Test Product Generator and replayed products only ever match synthetic products against alert rules belonging to whichever user has this flag set.'),
                Select::make('tier_override')
                    ->label('Tier override')
                    ->options(collect(Tier::cases())->mapWithKeys(fn (Tier $tier) => [$tier->value => ucfirst($tier->value)]))
                    ->nullable()
                    ->live(),
                Textarea::make('tier_override_reason')
                    ->label('Reason')
                    ->requiredWith('tier_override')
                    ->helperText('Required when setting a tier override — why is this subscriber being gifted or downgraded?'),
                Section::make(fn (?string $operation): string => $operation === 'create' ? 'Account setup' : 'Change Password')
                    ->description(fn (?string $operation): ?string => $operation === 'create' ? null : 'Leave both fields blank to keep the current password.')
                    ->schema([
                        Radio::make('password_setup')
                            ->label('')
                            ->options([
                                'send_reset_link' => 'Email the subscriber a password-reset link',
                                'set_directly' => 'Set the password now',
                            ])
                            ->default('send_reset_link')
                            ->live()
                            ->dehydrated(false)
                            ->visible(fn (?string $operation): bool => $operation === 'create'),
                        self::passwordField(),
                        self::passwordConfirmationField(),
                    ]),
            ]);
    }

    /**
     * On Edit, optional -- blank means "don't change the password"
     * (dehydrated() drops it from the saved data entirely rather than
     * saving an empty value), and any password meeting the app's default
     * policy is accepted (same shape as the dashboard's EditProfile
     * password field). On Create, only relevant when the admin chose to
     * set the password directly rather than email a reset link -- required
     * then, and held to the same character-class policy as the dashboard's
     * self-registration form so a staff-created account isn't weaker than
     * a self-registered one.
     */
    protected static function passwordField(): TextInput
    {
        return TextInput::make('password')
            ->password()
            ->revealable()
            ->requiredIf('password_setup', 'set_directly')
            ->rule(fn (?string $operation): Password => $operation === 'create'
                ? Password::min(8)->letters()->mixedCase()->numbers()->symbols()
                : Password::default())
            ->autocomplete('new-password')
            ->same('passwordConfirmation')
            ->visible(fn (?string $operation, Get $get): bool => $operation !== 'create' || $get('password_setup') === 'set_directly')
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
            ->visible(fn (?string $operation, Get $get): bool => $operation !== 'create' || $get('password_setup') === 'set_directly')
            ->dehydrated(false);
    }
}
