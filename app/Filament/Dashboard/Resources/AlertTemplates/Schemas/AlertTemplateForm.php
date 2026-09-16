<?php

namespace App\Filament\Dashboard\Resources\AlertTemplates\Schemas;

use App\Enums\AlertCategory;
use App\Enums\AlertTemplateSection;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AlertTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components(self::fields());
    }

    /**
     * @return array<Component>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->helperText('Your own label for this template, e.g. "Detailed Severe Weather".'),
            Select::make('category')
                ->label('Applies to')
                ->options([
                    AlertCategory::WeatherAlerts->value => AlertCategory::WeatherAlerts->label(),
                    AlertCategory::SpcWpc->value => AlertCategory::SpcWpc->label(),
                ])
                ->required()
                ->live()
                ->helperText('Only alert rules in this category can use this template.'),
            TextInput::make('subject_template')
                ->label('Custom subject line (optional)')
                ->maxLength(255)
                ->helperText('Leave blank to use the default. Placeholders: {product}, {location}, {category}.'),
            CheckboxList::make('sections')
                ->label('Include in message')
                ->columns(2)
                ->options(collect(AlertTemplateSection::cases())->mapWithKeys(fn (AlertTemplateSection $s): array => [$s->value => $s->label()]))
                ->helperText('Appears in this order in the message, regardless of check order. Leave all unchecked to send the raw product text instead.')
                ->visible(fn (Get $get): bool => $get('category') === AlertCategory::WeatherAlerts->value),
        ];
    }
}
