<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Pages;

use App\Actions\Alerts\SaveAlertRule;
use App\Enums\AlertCategory;
use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use App\Filament\Dashboard\Resources\AlertRules\Schemas\AlertRuleForm;
use App\Filament\Dashboard\Resources\AlertRules\Schemas\ProductMatchingTable;
use App\Support\AlertRuleFormUnits;
use App\Support\SpcWpcRiskLevels;
use App\Support\Units;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;

class EditAlertRule extends EditRecord
{
    use HasWizard;

    protected static string $resource = AlertRuleResource::class;

    /**
     * The rule row (which nulls its own matching columns) is written before afterSave() syncs
     * the per-product settings; one transaction keeps a failed sync from leaving it half-saved.
     */
    protected ?bool $hasDatabaseTransactions = true;

    /**
     * @return array<Step>
     */
    public function getSteps(): array
    {
        return AlertRuleForm::steps();
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

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['channel_ids'] = $this->record->notificationChannels->pluck('id')->all();

        if (ProductMatchingTable::supports($this->record->category)) {
            $data['product_settings'] = ProductMatchingTable::formState(auth()->user(), $this->record->category, $this->record);
        }

        if (($data['category'] ?? null) === AlertCategory::SpcWpc->value) {
            $data['risk_levels'] = SpcWpcRiskLevels::split($data['risk_levels'] ?? []);
        }

        return AlertRuleFormUnits::toDisplay($data, Units::for(auth()->user()));
    }

    /**
     * Persistence rules (units, risk-level flattening, clearing columns and the template that
     * the saved category does not own) live in SaveAlertRule, which the mobile API shares. A
     * hidden field is dropped from the dehydrated data, so the action, not the form state, is
     * what keeps a stale value from surviving a category change.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return app(SaveAlertRule::class)->attributes(auth()->user(), ProductMatchingTable::toSaveInput($data), $this->record);
    }

    protected function afterSave(): void
    {
        app(SaveAlertRule::class)->syncRelations($this->record, ProductMatchingTable::toSaveInput($this->data));
    }
}
