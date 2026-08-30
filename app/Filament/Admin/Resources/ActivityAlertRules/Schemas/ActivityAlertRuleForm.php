<?php

namespace App\Filament\Admin\Resources\ActivityAlertRules\Schemas;

use App\Enums\ActivityAlertScope;
use App\Enums\ActivityAlertType;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ActivityAlertRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->maxLength(255)
                    ->helperText('An admin-facing label for this rule.'),
                Select::make('scope')
                    ->options(collect(ActivityAlertScope::cases())->mapWithKeys(fn (ActivityAlertScope $s) => [$s->value => $s->label()]))
                    ->required()
                    ->live(),
                Select::make('users')
                    ->relationship('users', 'email')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->visible(fn (Get $get): bool => $get('scope') === ActivityAlertScope::SpecificUsers->value)
                    ->required(fn (Get $get): bool => $get('scope') === ActivityAlertScope::SpecificUsers->value),
                CheckboxList::make('activity_types')
                    ->options(collect(ActivityAlertType::cases())->mapWithKeys(fn (ActivityAlertType $t) => [$t->value => $t->label()]))
                    ->columns(2)
                    ->required(),
                TextInput::make('apprise_target')
                    ->label('Apprise target')
                    ->required()
                    ->helperText('An Apprise URL, e.g. slack://token/botname/channel.'),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }
}
