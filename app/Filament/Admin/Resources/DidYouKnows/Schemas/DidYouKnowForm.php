<?php

namespace App\Filament\Admin\Resources\DidYouKnows\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class DidYouKnowForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Textarea::make('body')
                    ->required()
                    ->rows(3),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }
}
