<?php

namespace App\Filament\Admin\Resources\TestProductScenarios\Pages;

use App\Actions\Testing\GenerateTestProduct;
use App\Actions\Testing\NotifyGenerationOutcome;
use App\Filament\Admin\Resources\TestProductScenarios\Schemas\TestProductScenarioForm;
use App\Filament\Admin\Resources\TestProductScenarios\TestProductScenarioResource;
use App\Models\TestProductScenario;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;

class EditTestProductScenario extends EditRecord
{
    use HasWizard;

    protected static string $resource = TestProductScenarioResource::class;

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Generate');
    }

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

        // `mode` is a UI-only, non-column field: it is never persisted, so
        // derive it from the relationship or a continuation would hydrate as
        // "New event" and hide its own VTEC action field.
        $data['mode'] = $this->record->continues_scenario_id !== null ? 'continue' : 'new';

        return $data;
    }

    /**
     * On a continuation, product_id and input.wfo/input.vtec_event_number
     * are all hidden (mode === 'continue'), so Filament's default
     * isDehydratedWhenHidden(false) strips them from $data -- and
     * EditRecord replaces the whole `input` array, which would destroy the
     * stored event identity and silently turn a CON/EXP/CAN into a NEW with
     * a fresh event number. Force them back from the persisted record; see
     * CreateTestProductScenario::mutateFormDataBeforeCreate() for the same
     * narrow rescue on Create.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record->continues_scenario_id === null) {
            return $data;
        }

        $data['product_id'] = $this->record->product_id;
        $data['input'] = array_merge($data['input'] ?? [], array_filter([
            'wfo' => $this->record->input['wfo'] ?? null,
            'vtec_event_number' => $this->record->input['vtec_event_number'] ?? null,
        ], fn (mixed $value): bool => $value !== null));

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var TestProductScenario $scenario */
        $scenario = $this->record;

        app(NotifyGenerationOutcome::class)(app(GenerateTestProduct::class)($scenario));
    }
}
