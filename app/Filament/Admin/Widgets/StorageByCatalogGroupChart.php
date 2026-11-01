<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\StorageDimension;
use App\Support\ProductStorage\BucketChart;
use Filament\Widgets\ChartWidget;

class StorageByCatalogGroupChart extends ChartWidget
{
    use BucketChart;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Storage by catalog group';

    protected function dimension(): StorageDimension
    {
        return StorageDimension::CatalogGroup;
    }
}
