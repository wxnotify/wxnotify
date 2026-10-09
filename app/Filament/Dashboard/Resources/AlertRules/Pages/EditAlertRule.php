<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Pages;

use App\Actions\Alerts\SaveAlertRule;
use App\Enums\AlertCategory;
use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use App\Filament\Dashboard\Resources\AlertRules\Schemas\AlertRuleForm;
use App\Models\Product;
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
        $data['product_ids'] = $this->record->products
            ->groupBy(fn (Product $product): ?string => $product->category?->value)
            ->map(fn ($products) => $products->pluck('id')->all())
            ->all();
        $data['channel_ids'] = $this->record->notificationChannels->pluck('id')->all();
        $data['location_ids'] = $this->record->locations->pluck('id')->all();

        if (($data['category'] ?? null) === AlertCategory::SpcWpc->value) {
            $data['risk_levels'] = SpcWpcRiskLevels::split($data['risk_levels'] ?? []);
        }

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
        return app(SaveAlertRule::class)->attributes(auth()->user(), $data, $this->record);
    }

    protected function afterSave(): void
    {
        app(SaveAlertRule::class)->syncRelations($this->record, $this->data);
    }
}
