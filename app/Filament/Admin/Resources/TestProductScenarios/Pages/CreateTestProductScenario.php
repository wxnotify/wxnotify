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

    /**
     * TestProductScenarioForm::inheritFromContinuedScenario() copies the
     * continued event's product/wfo/event-number/content into LIVE form
     * state via `Set $set`, but `product_id` and `input.wfo` are only ever
     * set while `mode === 'continue'` -- which is exactly when both fields
     * are `->visible(false)`. Filament's default `isDehydratedWhenHidden(false)`
     * means dehydrateState() drops a hidden field's key from $data entirely
     * rather than dehydrating its already-set value (the same trap
     * documented in .ai/rules/filament.md for EditAlertRule's
     * alert_template_id, just hit here on a Create page instead of an
     * Edit's save). Left alone, `static::getModel()::create($data)` would
     * persist `product_id = null` and an `input` with no wfo/event
     * number/content at all, even though the wizard showed them filled in.
     *
     * Force them back in here, independent of dehydration, as the actual
     * point that writes to the database -- re-derived from
     * `continues_scenario_id` (which DOES survive dehydration, since it's
     * visible exactly when `product_id`/`input.wfo` are hidden) via the
     * same TestProductScenarioForm::inheritedDataFor() helper the live form
     * uses, so the two can't drift apart.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $continuedScenarioId = $data['continues_scenario_id'] ?? null;
        if ($continuedScenarioId === null) {
            return $data;
        }

        $inherited = TestProductScenarioForm::inheritedDataFor((int) $continuedScenarioId);
        if ($inherited === null) {
            return $data;
        }

        $data['product_id'] = $inherited['product_id'];
        $data['input'] = array_merge($data['input'] ?? [], $inherited['input']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
