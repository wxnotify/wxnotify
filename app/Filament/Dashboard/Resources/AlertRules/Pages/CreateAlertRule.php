<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Pages;

use App\Actions\Alerts\SaveAlertRule;
use App\Exceptions\AlertRuleLimitReached;
use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use App\Filament\Dashboard\Resources\AlertRules\Schemas\AlertRuleForm;
use App\Filament\Dashboard\Resources\AlertRules\Schemas\ProductMatchingTable;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Eloquent\Model;

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

    /**
     * Refuses before validating, at the plan's alert limit, the way CreateLocation does.
     */
    protected function beforeValidate(): void
    {
        $user = auth()->user();

        if ($user->canCreateAlertRule()) {
            return;
        }

        $this->refuse(AlertRuleLimitReached::limit($user->alertRuleLimit())->getMessage());
    }

    /**
     * The model's own guard is the authority: a save that races past the early check ends in a
     * notification, not a 500.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return parent::handleRecordCreation($data);
        } catch (AlertRuleLimitReached $exception) {
            $this->refuse($exception->getMessage());
        }
    }

    protected function refuse(string $message): never
    {
        Notification::make()
            ->title('Alert limit reached')
            ->body($message)
            ->danger()
            ->send();

        $this->halt();
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
