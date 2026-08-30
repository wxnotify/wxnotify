<?php

namespace App\Filament\Admin\Resources\Products\Schemas;

use App\Enums\ProductCategory;
use App\Enums\ProductFamily;
use App\Enums\ProductStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('code')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('family')
                    ->options(collect(ProductFamily::cases())->mapWithKeys(fn (ProductFamily $f) => [$f->value => $f->label()]))
                    ->required(),
                Select::make('category')
                    ->options(collect(ProductCategory::cases())->mapWithKeys(fn (ProductCategory $c) => [$c->value => $c->label()]))
                    ->helperText('Hazard-type grouping, distinct from family (which drives alert-wizard filtering).'),
                Toggle::make('has_polygon'),
                Toggle::make('wea_warning')
                    ->helperText('Activates the Wireless Emergency Alerts (cell-phone) system when issued.'),
                TagsInput::make('risk_level_options')
                    ->helperText('Leave blank for products with no risk-level filter.'),
                Select::make('status')
                    ->options(collect(ProductStatus::cases())->mapWithKeys(fn (ProductStatus $s) => [$s->value => ucfirst($s->value)]))
                    ->default(ProductStatus::Draft)
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull()
                    ->rows(4),
                Textarea::make('example')
                    ->columnSpanFull()
                    ->rows(8)
                    ->helperText('A real sample of this product\'s issued text, for reference.'),
            ]);
    }
}
