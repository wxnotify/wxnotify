<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Pages;

use App\Enums\AlertCategory;
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

    /**
     * AlertRuleForm::clearAlertTemplateIfCategoryUnsupported() clears
     * alert_template_id in live form STATE the moment category changes
     * away from weather_alerts/spc_wpc, but that alone doesn't survive a
     * save on THIS page: alertTemplateSelect() is hidden (not just
     * disabled) once category no longer supports it, and Filament's
     * default isDehydratedWhenHidden(false) means dehydrateState() removes
     * a hidden field's key from $data entirely (confirmed by reading
     * vendor/filament/schemas/src/Components/Concerns/HasState.php) rather
     * than dehydrating its current (already-cleared) value. Since
     * EditRecord's default handleRecordUpdate() does $record->update($data)
     * -- plain mass assignment -- a key that's simply absent from $data
     * leaves that column untouched, so a rule that already had
     * alert_template_id set before the category change keeps its stale
     * value in the database even though the in-wizard state (and a fresh
     * Create) show it cleared. Force it here as the actual point that
     * writes to the database, independent of dehydration.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! in_array($data['category'] ?? null, [AlertCategory::WeatherAlerts->value, AlertCategory::SpcWpc->value], true)) {
            $data['alert_template_id'] = null;
        }

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
