<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Pages;

use App\Actions\Alerts\SaveAlertRule;
use App\Enums\AlertCategory;
use App\Exceptions\AlertRuleLimitReached;
use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use App\Filament\Dashboard\Resources\AlertRules\Schemas\AlertRuleForm;
use App\Filament\Dashboard\Resources\AlertRules\Schemas\ProductMatchingTable;
use App\Support\AlertRuleFormUnits;
use App\Support\SpcWpcRiskLevels;
use App\Support\Units;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Eloquent\Model;

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
            $data['risk_levels'] = SpcWpcRiskLevels::forForm($this->record);
        }

        if (($data['category'] ?? null) === AlertCategory::OutlookReport->value) {
            $data['delivery_time'] = $this->record->outlookSendTime();
            $data['report_mode'] = $this->record->report_mode?->value ?? 'every_day';
            $data['location_ids'] = $this->record->locations->pluck('id')->all();
        }

        return AlertRuleFormUnits::toDisplay($data, Units::for(auth()->user()));
    }

    /**
     * Persistence rules (units, per-product risk levels, clearing columns and the template that
     * the saved category does not own) live in SaveAlertRule, which the mobile API shares. A
     * hidden field is dropped from the dehydrated data, so the action, not the form state, is
     * what keeps a stale value from surviving a category change.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->saveInput = ProductMatchingTable::toSaveInput($data, auth()->user());

        return AlertRuleForm::saveForForm(fn (): array => app(SaveAlertRule::class)->attributes(auth()->user(), $this->saveInput, $this->record));
    }

    /**
     * The model's own guard is the authority: a switch to an Outlook Report that races past the
     * early check ends in a notification, not a 500.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return parent::handleRecordUpdate($record, $data);
        } catch (AlertRuleLimitReached $exception) {
            Notification::make()
                ->title('Alert limit reached')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }
    }

    protected function afterSave(): void
    {
        app(SaveAlertRule::class)->syncRelations($this->record, $this->saveInput);
    }
}
