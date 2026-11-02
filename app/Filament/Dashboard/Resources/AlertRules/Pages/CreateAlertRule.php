<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Pages;

use App\Actions\Alerts\SaveAlertRule;
use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use App\Filament\Dashboard\Resources\AlertRules\Schemas\AlertRuleForm;
use App\Filament\Dashboard\Resources\AlertRules\Schemas\ProductMatchingTable;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;

class CreateAlertRule extends CreateRecord
{
    use HasWizard;

    protected static string $resource = AlertRuleResource::class;

    /**
     * The rule row is written before afterCreate() syncs its products and channels; one
     * transaction keeps a failed sync from leaving a rule behind.
     */
    protected ?bool $hasDatabaseTransactions = true;

    /**
     * What SaveAlertRule saves, built once from the validated, dehydrated form data before the
     * record is written and reused for the relations after it: the raw `$this->data` may hold
     * keys no field validated.
     *
     * @var array<string, mixed>
     */
    protected array $saveInput = [];

    /**
     * @return array<Step>
     */
    public function getSteps(): array
    {
        return AlertRuleForm::steps();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->saveInput = ProductMatchingTable::toSaveInput($data, auth()->user());

        return AlertRuleForm::saveForForm(fn (): array => app(SaveAlertRule::class)->attributes(auth()->user(), $this->saveInput));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterCreate(): void
    {
        app(SaveAlertRule::class)->syncRelations($this->record, $this->saveInput);
    }
}
