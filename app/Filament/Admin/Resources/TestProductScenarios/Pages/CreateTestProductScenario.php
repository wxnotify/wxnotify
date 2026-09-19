<?php

namespace App\Filament\Admin\Resources\TestProductScenarios\Pages;

use App\Actions\Testing\GenerateTestProduct;
use App\Actions\Testing\NotifyGenerationOutcome;
use App\Filament\Admin\Resources\TestProductScenarios\Pages\Concerns\OffersSaveWithoutGenerating;
use App\Filament\Admin\Resources\TestProductScenarios\Schemas\TestProductScenarioForm;
use App\Filament\Admin\Resources\TestProductScenarios\TestProductScenarioResource;
use App\Models\TestProductScenario;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;

class CreateTestProductScenario extends CreateRecord
{
    use HasWizard, OffersSaveWithoutGenerating {
        OffersSaveWithoutGenerating::getWizardComponent insteadof HasWizard;
        HasWizard::getWizardComponent as baseWizardComponent;
    }

    protected static string $resource = TestProductScenarioResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * The wizard's final action generates and dispatches a product, not just
     * saves a row -- so it's labeled "Generate".
     */
    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Generate');
    }

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
     * state via `Set $set`, but `product_id` and `input.wfo`/
     * `input.vtec_event_number` are only ever set while `mode === 'continue'`
     * -- which is exactly when all three fields are `->visible(false)`.
     * Filament's default `isDehydratedWhenHidden(false)` means
     * dehydrateState() drops a hidden field's key from $data entirely
     * rather than dehydrating its already-set value (the same trap
     * documented in .ai/rules/filament.md for EditAlertRule's
     * alert_template_id, just hit here on a Create page instead of an
     * Edit's save). Left alone, `static::getModel()::create($data)` would
     * persist `product_id = null` and no wfo/event number in `input`, even
     * though the wizard showed them filled in.
     *
     * Force ONLY those three back in here, independent of dehydration, as
     * the actual point that writes to the database -- re-derived from
     * `continues_scenario_id` (which DOES survive dehydration, since it's
     * visible exactly when the other three are hidden) via
     * TestProductScenarioForm::dehydrationSafeInheritedFieldsFor().
     *
     * Deliberately does NOT reuse inheritedDataFor() (the FULL inheritance,
     * including geography/content input.* keys) here -- once Steps 2/3
     * (Geography, Content) wire up real fields for those keys, they stay
     * visible and editable regardless of mode, so they dehydrate normally
     * and `$data['input']` will already hold whatever the admin actually
     * submitted for them. Overwriting that with the originally-inherited
     * value here would silently discard a deliberate edit made during a
     * "continue" flow. See dehydrationSafeInheritedFieldsFor()'s own
     * docblock for the full reasoning.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $continuedScenarioId = $data['continues_scenario_id'] ?? null;
        if ($continuedScenarioId === null) {
            return $data;
        }

        $rescued = TestProductScenarioForm::dehydrationSafeInheritedFieldsFor((int) $continuedScenarioId);
        if ($rescued === null) {
            return $data;
        }

        $data['product_id'] = $rescued['product_id'];
        $data['input'] = array_merge($data['input'] ?? [], $rescued['input']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    public function saveWithoutGenerating(): void
    {
        $this->generateAfterSaving = false;
        $this->create();
    }

    protected function afterCreate(): void
    {
        if (! $this->generateAfterSaving) {
            return;
        }

        /** @var TestProductScenario $scenario */
        $scenario = $this->record;

        app(NotifyGenerationOutcome::class)(app(GenerateTestProduct::class)($scenario));
    }
}
