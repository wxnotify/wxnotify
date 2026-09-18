<?php

namespace App\Filament\Admin\Resources\TestProductScenarios\Schemas;

use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;

class TestProductScenarioForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(null)
            ->components([
                Wizard::make(self::steps())->contained(false),
            ]);
    }

    /**
     * @return array<Step>
     */
    public static function steps(): array
    {
        return [
            Step::make('Product & Timing'),
            Step::make('Geography'),
            Step::make('Content'),
            Step::make('Review & Generate'),
        ];
    }
}
