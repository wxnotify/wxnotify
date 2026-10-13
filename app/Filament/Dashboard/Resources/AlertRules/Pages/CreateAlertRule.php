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
     * @return array<Step>
     */
    public function getSteps(): array
    {
        return AlertRuleForm::steps();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return app(SaveAlertRule::class)->attributes(auth()->user(), ProductMatchingTable::toSaveInput($data));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterCreate(): void
    {
        app(SaveAlertRule::class)->syncRelations($this->record, ProductMatchingTable::toSaveInput($this->data));
    }
}
