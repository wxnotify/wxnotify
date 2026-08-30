<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Pages;

use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use App\Filament\Dashboard\Resources\AlertRules\Schemas\AlertRuleForm;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;

class EditAlertRule extends EditRecord
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

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['product_ids'] = $this->record->products->pluck('id')->all();
        $data['channel_ids'] = $this->record->notificationChannels->pluck('id')->all();

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->products()->sync($this->data['product_ids'] ?? []);
        $this->record->notificationChannels()->sync($this->data['channel_ids'] ?? []);
    }
}
