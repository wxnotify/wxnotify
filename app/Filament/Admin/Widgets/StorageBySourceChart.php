<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\StorageDimension;
use App\Support\ProductStorage\BucketChart;
use Filament\Widgets\ChartWidget;

class StorageBySourceChart extends ChartWidget
{
    use BucketChart;

    protected ?string $heading = 'Storage by source';

    protected function dimension(): StorageDimension
    {
        return StorageDimension::Source;
    }
}
