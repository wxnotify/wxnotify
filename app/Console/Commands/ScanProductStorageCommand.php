<?php

namespace App\Console\Commands;

use App\Jobs\ScanProductStorageJob;
use Illuminate\Console\Command;

class ScanProductStorageCommand extends Command
{
    protected $signature = 'storage:scan-products';

    protected $description = 'Queue a scan of the product volumes for the Product Storage page';

    public function handle(): int
    {
        ScanProductStorageJob::dispatch();

        $this->info('Queued a product storage scan.');

        return self::SUCCESS;
    }
}
