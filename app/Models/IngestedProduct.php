<?php

namespace App\Models;

use App\Actions\Pipeline\GenerateViewSlug;
use Database\Factories\IngestedProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Fillable([
    'cccc', 'ttaaii', 'awipsid', 'nwws_id', 'wmo_ddhhmm', 'text', 'file_path', 'source', 'wmo_header_key', 'received_at',
    'product_id', 'ugc_codes', 'polygon', 'risk_levels',
    'vtec_office', 'vtec_phenomenon', 'vtec_significance', 'vtec_event_number', 'vtec_year',
    'vtec_action', 'vtec_begin', 'vtec_end',
    'format', 'msg_type', 'event', 'severity', 'urgency', 'certainty',
    'headline', 'description', 'instruction', 'event_motion_description',
    'awips_identifier', 'wmo_identifier',
    'hazard', 'hazard_source', 'impact', 'locations_impacted', 'call_to_action',
    'is_test', 'test_product_scenario_id', 'replay_of_id',
])]
class IngestedProduct extends Model
{
    /** @use HasFactory<IngestedProductFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'ugc_codes' => 'array',
            'polygon' => 'array',
            'risk_levels' => 'array',
            'vtec_begin' => 'datetime',
            'vtec_end' => 'datetime',
            'is_test' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (IngestedProduct $product): void {
            if ($product->view_slug !== null) {
                return;
            }

            try {
                $product->forceFill(['view_slug' => app(GenerateViewSlug::class)($product)])->saveQuietly();
            } catch (Throwable $e) {
                Log::warning('Failed to generate a view slug', ['id' => $product->getKey(), 'exception' => $e->getMessage()]);
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function replayOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replay_of_id');
    }

    public function replays(): HasMany
    {
        return $this->hasMany(self::class, 'replay_of_id');
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(TestProductScenario::class, 'test_product_scenario_id');
    }

    public function viewUrl(): ?string
    {
        if ($this->view_slug === null) {
            return null;
        }

        $productDomain = config('wxnotify.product_domain');
        $base = $productDomain ? 'https://'.$productDomain : rtrim((string) config('app.url'), '/');

        return $base.route('view-product.show', ['slug' => $this->view_slug], absolute: false);
    }

    /**
     * Decodes cccc/ttaaii/awipsid/wmo_ddhhmm/nwws_id from an nwws-go-client
     * archive filename: [cccc]_[ttaaii]-[awipsid].[ddHHMM]_[id].txt — see
     * pipeline/nwws-go-client/internal/product/product.go's Filename().
     *
     * wmo_ddhhmm is day/hour/minute only (no month or year — a property of
     * the WMO header convention the filename mirrors, not a bug here), so
     * it's a display-only field, never treated as a real sortable date —
     * see IngestNwwsProductController for what *is* used for that.
     *
     * Returns all-null fields rather than throwing when the filename
     * doesn't match the expected shape — an unparseable filename still gets
     * ingested with its raw text, just without decoded metadata.
     *
     * cccc/ttaaii/awipsid may theoretically contain '.', '_', or '-' since
     * the upstream Go client's sanitizeID() doesn't strip those — real-world
     * NWS product identifiers never do, so this is accepted as a non-issue
     * in practice, not silently guaranteed by this regex. If one of them
     * ever did, the greedy pattern below would silently mis-split the three
     * fields rather than fail.
     *
     * @return array{cccc: ?string, ttaaii: ?string, awipsid: ?string, wmo_ddhhmm: ?string, nwws_id: ?string}
     */
    public static function parseFilename(string $filename): array
    {
        $pattern = '/^([a-z0-9._-]+)_([a-z0-9._-]+)-([a-z0-9._-]+)\.(\d{6})_(.+)\.txt$/';

        if (! preg_match($pattern, strtolower($filename), $matches)) {
            return [
                'cccc' => null,
                'ttaaii' => null,
                'awipsid' => null,
                'wmo_ddhhmm' => null,
                'nwws_id' => null,
            ];
        }

        return [
            'cccc' => $matches[1],
            'ttaaii' => $matches[2],
            'awipsid' => $matches[3],
            'wmo_ddhhmm' => $matches[4],
            'nwws_id' => $matches[5],
        ];
    }
}
