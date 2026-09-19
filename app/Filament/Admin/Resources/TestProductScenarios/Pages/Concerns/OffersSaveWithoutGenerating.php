<?php

namespace App\Filament\Admin\Resources\TestProductScenarios\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Wizard;
use Illuminate\Support\HtmlString;

trait OffersSaveWithoutGenerating
{
    protected bool $generateAfterSaving = true;

    public function getWizardComponent(): Component
    {
        /** @var Wizard $wizard */
        $wizard = $this->baseWizardComponent();

        $saveOnly = Action::make('saveOnly')
            ->label('Save')
            ->color('gray')
            ->livewireClickHandlerEnabled(false)
            ->alpineClickHandler('$wire.saveWithoutGenerating()')
            ->livewire($this);

        return $wizard->submitAction(new HtmlString(
            $saveOnly->toHtml().$this->getSubmitFormAction()->toHtml(),
        ));
    }
}
