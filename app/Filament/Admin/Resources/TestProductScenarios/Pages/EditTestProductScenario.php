<?php

namespace App\Filament\Admin\Resources\TestProductScenarios\Pages;

use App\Actions\Testing\GenerateTestProduct;
use App\Actions\Testing\NotifyGenerationOutcome;
use App\Filament\Admin\Resources\TestProductScenarios\Schemas\TestProductScenarioForm;
use App\Filament\Admin\Resources\TestProductScenarios\TestProductScenarioResource;
use App\Models\TestProductScenario;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;

class EditTestProductScenario extends EditRecord
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

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * A test dispatch is meant to look freshly issued every time it's sent
     * -- see the design doc's "Repeat & Reuse" section. Every other stored
     * input field hydrates as-is; only issued_at is reset here, each time
     * this scenario is opened, rather than replaying its stale original
     * value.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['input']['issued_at'] = now();

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var TestProductScenario $scenario */
        $scenario = $this->record;

        app(NotifyGenerationOutcome::class)(app(GenerateTestProduct::class)($scenario));
    }
}
