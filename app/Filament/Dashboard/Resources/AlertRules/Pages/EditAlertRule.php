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

        // county_zone_codes is a multi-select over a plain comma-joined
        // string column (not an array/JSON-cast one like product_ids), so
        // Filament's default OptionsArrayStateCast for multiple() selects
        // runs json_decode() on it during hydration -- a non-JSON string
        // like "NYZ072,NYC061" fails to decode and silently becomes [],
        // wiping the field and blocking save with a false "required" error.
        // Pre-splitting into a real array here, the same way product_ids
        // and channel_ids are already pre-shaped above, sidesteps the cast
        // entirely since is_array() is already true by the time it runs.
        $data['county_zone_codes'] = filled($data['county_zone_codes'] ?? null)
            ? explode(',', $data['county_zone_codes'])
            : [];

        return $data;
    }

    protected function afterSave(): void
    {
        // ->filter(is_numeric(...)) is defense-in-depth — see
        // AlertRuleForm::seedProductIdsDefaults()'s docblock and
        // CreateAlertRule::afterCreate()'s matching comment for why.
        $this->record->products()->sync(
            collect($this->data['product_ids'] ?? [])->flatten()->filter(fn ($id): bool => is_numeric($id))->values()->all()
        );
        $this->record->notificationChannels()->sync($this->data['channel_ids'] ?? []);
    }
}
