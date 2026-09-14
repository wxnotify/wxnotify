<?php

namespace App\Filament\Admin\Resources\Announcements\Schemas;

use App\Enums\AnnouncementScope;
use App\Enums\Tier;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Marcelodelgado\Announcements\Enums\AnnouncementType;

class AnnouncementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(2)
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255),
                        Select::make('type')
                            ->options(collect(AnnouncementType::cases())->mapWithKeys(fn (AnnouncementType $type) => [$type->value => $type->label()]))
                            ->required()
                            ->native(false),
                    ]),
                Textarea::make('body')
                    ->required()
                    ->rows(4),
                Grid::make(2)
                    ->schema([
                        Select::make('scope')
                            ->options(collect(AnnouncementScope::cases())->mapWithKeys(fn (AnnouncementScope $scope) => [$scope->value => $scope->label()]))
                            ->required()
                            ->live()
                            ->default(AnnouncementScope::AllSubscribers)
                            ->helperText('Who sees this announcement.'),
                        CheckboxList::make('tiers')
                            ->options(collect(Tier::cases())->mapWithKeys(fn (Tier $tier) => [$tier->value => ucfirst($tier->value)]))
                            ->visible(fn (Get $get): bool => $get('scope') === AnnouncementScope::SpecificTiers->value)
                            ->required(fn (Get $get): bool => $get('scope') === AnnouncementScope::SpecificTiers->value),
                    ]),
                Select::make('targetedUsers')
                    ->relationship('targetedUsers', 'email')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->visible(fn (Get $get): bool => $get('scope') === AnnouncementScope::SpecificUsers->value)
                    ->required(fn (Get $get): bool => $get('scope') === AnnouncementScope::SpecificUsers->value),
                Grid::make(2)
                    ->schema([
                        Toggle::make('is_active')
                            ->default(true),
                        Toggle::make('is_dismissible')
                            ->default(true),
                    ]),
                Grid::make(2)
                    ->schema([
                        DateTimePicker::make('starts_at')
                            ->seconds(false)
                            ->helperText('Leave blank to start immediately.'),
                        DateTimePicker::make('expires_at')
                            ->seconds(false)
                            ->helperText('Leave blank to never expire.'),
                    ]),
            ]);
    }
}
