<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Pages;

use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use App\Filament\Dashboard\Resources\AlertRules\Schemas\AlertRuleForm;
use App\Models\Product;
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
        $data['product_ids'] = $this->record->products
            ->groupBy(fn (Product $product): ?string => $product->category?->value)
            ->map(fn ($products) => $products->pluck('id')->all())
            ->all();
        $data['channel_ids'] = $this->record->notificationChannels->pluck('id')->all();

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->products()->sync(collect($this->data['product_ids'] ?? [])->flatten()->all());
        $this->record->notificationChannels()->sync($this->data['channel_ids'] ?? []);
    }
}
