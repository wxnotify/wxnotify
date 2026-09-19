<?php

namespace App\Actions\Testing;

use App\Models\IngestedProduct;
use Filament\Notifications\Notification;

/**
 * Reports the honest outcome of a GenerateTestProduct run to the admin:
 * a failure, a product the pipeline ingested but could not recognize
 * (product_id unresolved -- a parser or catalog gap), or a real success.
 */
class NotifyGenerationOutcome
{
    public function __invoke(?IngestedProduct $result): void
    {
        if ($result === null) {
            Notification::make()
                ->title('Failed to generate the test product')
                ->body('The test product could not be generated. Check the scenario\'s product and fields, then try again.')
                ->danger()
                ->send();

            return;
        }

        if ($result->product_id === null) {
            Notification::make()
                ->title('Test product generated, but not recognized')
                ->body('The pipeline ingested it but could not resolve it to a catalog product, so product-based alert rules cannot match it. This is usually a parser or catalog gap for this product type.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Test product generated')
            ->body('It was ingested through the real pipeline and matched against the fixture test account.')
            ->success()
            ->send();
    }
}
