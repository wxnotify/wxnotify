<?php

namespace App\Actions\Pipeline;

use App\Models\IngestedProduct;
use Illuminate\Support\Str;
use RuntimeException;

class ReplayIngestedProduct
{
    public function __construct(private readonly IngestProductText $ingestProductText) {}

    public static function allowed(): bool
    {
        return ! app()->environment('production');
    }

    public function __invoke(IngestedProduct $original): IngestedProduct
    {
        if (! self::allowed()) {
            throw new RuntimeException('Replaying products is disabled in production.');
        }

        $replay = ($this->ingestProductText)(
            $original->text,
            'replay/'.$original->id.'-'.Str::uuid(),
            [
                'cccc' => $original->cccc,
                'ttaaii' => $original->ttaaii,
                'awipsid' => $original->awipsid,
                'wmo_ddhhmm' => $original->wmo_ddhhmm,
                'nwws_id' => null,
            ],
            $original->format === 'cap',
            [
                'is_test' => true,
                'source' => $original->source,
                'wmo_header_key' => null,
                'replay_of_id' => $original->id,
                'test_product_scenario_id' => null,
            ],
        );

        if ($replay === null) {
            throw new RuntimeException("Product {$original->id} could not be re-ingested.");
        }

        return $replay;
    }
}
