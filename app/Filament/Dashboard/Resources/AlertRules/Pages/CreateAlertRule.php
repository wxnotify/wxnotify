<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Pages;

use App\Actions\Alerts\SaveAlertRule;
use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use App\Filament\Dashboard\Resources\AlertRules\Schemas\AlertRuleForm;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;

class CreateAlertRule extends CreateRecord
{
    use HasWizard;

    protected static string $resource = AlertRuleResource::class;

    /**
     * @return array<Step>
     */
    public function getSteps(): array
    {
        return AlertRuleForm::steps();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return app(SaveAlertRule::class)->attributes(auth()->user(), $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterCreate(): void
    {
        app(SaveAlertRule::class)->syncRelations($this->record, $this->data);
    }
}
