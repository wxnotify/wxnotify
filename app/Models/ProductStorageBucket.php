<?php

namespace App\Models;

use App\Enums\StorageDimension;
use Database\Factories\ProductStorageBucketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_storage_scan_id', 'dimension', 'label', 'bytes', 'file_count'])]
class ProductStorageBucket extends Model
{
    /** @use HasFactory<ProductStorageBucketFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dimension' => StorageDimension::class,
        ];
    }

    /**
     * @return BelongsTo<ProductStorageScan, $this>
     */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(ProductStorageScan::class, 'product_storage_scan_id');
    }
}
