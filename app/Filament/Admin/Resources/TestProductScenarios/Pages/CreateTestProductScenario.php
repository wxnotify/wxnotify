<?php

namespace App\Filament\Admin\Resources\TestProductScenarios\Pages;

use App\Filament\Admin\Resources\TestProductScenarios\Schemas\TestProductScenarioForm;
use App\Filament\Admin\Resources\TestProductScenarios\TestProductScenarioResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;

class CreateTestProductScenario extends CreateRecord
{
    use HasWizard;

    protected static string $resource = TestProductScenarioResource::class;

    /**
     * @return array<Step>
     */
    public function getSteps(): array
    {
        return TestProductScenarioForm::steps();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
