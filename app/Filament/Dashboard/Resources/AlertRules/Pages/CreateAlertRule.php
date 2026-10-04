<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Pages;

use App\Enums\AlertCategory;
use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use App\Filament\Dashboard\Resources\AlertRules\Schemas\AlertRuleForm;
use App\Support\AlertRuleFormUnits;
use App\Support\SpcWpcRiskLevels;
use App\Support\Units;
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
        $data['user_id'] = auth()->id();

        if (($data['category'] ?? null) === AlertCategory::SpcWpc->value) {
            $data['risk_levels'] = SpcWpcRiskLevels::flatten($data['risk_levels'] ?? []);
        }

        return AlertRuleFormUnits::toStorage($data, Units::for(auth()->user()));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterCreate(): void
    {
        // ->filter(is_numeric(...)) is defense-in-depth: product_ids is keyed
        // per product category (see AlertRuleForm::seedProductIdsDefaults()'s
        // docblock for the underlying bug this guards against), and a
        // category whose value is ever something other than a real array of
        // ids (e.g. a stray bool/null) would otherwise reach sync() and
        // fail with a Postgres bigint cast error instead of just being
        // silently excluded.
        $this->record->products()->sync(
            collect($this->data['product_ids'] ?? [])->flatten()->filter(fn ($id): bool => is_numeric($id))->values()->all()
        );
        $this->record->notificationChannels()->sync($this->data['channel_ids'] ?? []);
        $this->record->locations()->sync($this->data['location_ids'] ?? []);
    }
}
