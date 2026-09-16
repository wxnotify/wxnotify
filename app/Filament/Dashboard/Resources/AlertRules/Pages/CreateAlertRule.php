<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Pages;

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
        $data['user_id'] = auth()->id();

        return $data;
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
    }
}
