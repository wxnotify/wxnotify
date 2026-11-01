<?php

namespace App\Models;

use App\Enums\StorageDimension;
use Database\Factories\ProductStorageScanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'status', 'started_at', 'finished_at', 'error', 'nwws_bytes', 'emwin_byteblaster_bytes',
    'emwin_http_bytes', 'file_count', 'oldest_file_at', 'disks',
])]
class ProductStorageScan extends Model
{
    /** @use HasFactory<ProductStorageScanFactory> */
    use HasFactory;

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'oldest_file_at' => 'datetime',
            'disks' => 'array',
        ];
    }

    /**
     * @return HasMany<ProductStorageBucket, $this>
     */
    public function buckets(): HasMany
    {
        return $this->hasMany(ProductStorageBucket::class);
    }

    /**
     * @param  Builder<ProductStorageScan>  $query
     */
    public function scopeComplete(Builder $query): void
    {
        $query->where('status', self::STATUS_COMPLETE);
    }

    public static function latestComplete(): ?self
    {
        return static::query()->complete()->orderByDesc('finished_at')->orderByDesc('id')->first();
    }

    public static function latest(): ?self
    {
        return static::query()->orderByDesc('id')->first();
    }

    public function totalBytes(): int
    {
        return (int) $this->nwws_bytes + (int) $this->emwin_byteblaster_bytes + (int) $this->emwin_http_bytes;
    }

    /**
     * @return Collection<int, ProductStorageBucket>
     */
    public function bucketsFor(StorageDimension $dimension): Collection
    {
        return $this->buckets()
            ->where('dimension', $dimension->value)
            ->orderByDesc('bytes')
            ->get();
    }
}
