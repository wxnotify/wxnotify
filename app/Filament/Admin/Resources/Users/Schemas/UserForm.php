<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Enums\Tier;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

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
            ]);
    }
}
