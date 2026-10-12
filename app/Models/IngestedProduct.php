<?php

namespace App\Models;

use App\Actions\Pipeline\GenerateViewSlug;
use Database\Factories\IngestedProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Fillable([
    'cccc', 'ttaaii', 'awipsid', 'nwws_id', 'wmo_ddhhmm', 'text', 'file_path', 'source', 'wmo_header_key', 'received_at',
    'product_id', 'ugc_codes', 'polygon', 'risk_levels', 'risk_level_polygons',
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
            'risk_level_polygons' => 'array',
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

    /**
     * The bulletin's events, one per (section x VTEC line), in bulletin order. Empty for a
     * non-VTEC product and for a bulletin stored before segments existed.
     *
     * @return HasMany<IngestedProductSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(IngestedProductSegment::class)->orderBy('position');
    }

    /**
     * An unsaved, in-memory copy of this bulletin that carries one segment's identity: its catalog
     * product, zones, polygon and VTEC fields. The geography matchers read those from an
     * IngestedProduct, so matching a segment is matching this copy. Never save it.
     */
    public function asSegment(IngestedProductSegment $segment): static
    {
        $view = new static;
        $view->setRawAttributes($this->getAttributes());
        $view->forceFill([
            'product_id' => $segment->product_id,
            'ugc_codes' => $segment->ugc_codes,
            'polygon' => $segment->polygon,
            'vtec_office' => $segment->vtec_office,
            'vtec_phenomenon' => $segment->vtec_phenomenon,
            'vtec_significance' => $segment->vtec_significance,
            'vtec_event_number' => $segment->vtec_event_number,
            'vtec_year' => $segment->vtec_year,
            'vtec_action' => $segment->vtec_action,
            'vtec_begin' => $segment->vtec_begin,
            'vtec_end' => $segment->vtec_end,
        ]);

        return $view;
    }

    /**
     * The listed segments of this bulletin that still exist, in bulletin order, with their catalog
     * product loaded. An id that is gone (a rebuild replaced the segments) is ignored.
     *
     * @param  list<int>  $segmentIds
     * @return EloquentCollection<int, IngestedProductSegment>
     */
    public function matchedSegments(array $segmentIds): EloquentCollection
    {
        if ($segmentIds === []) {
            return new EloquentCollection;
        }

        return $this->segments()->with('product')->whereKey($segmentIds)->get();
    }

    /**
     * What a notification calls this bulletin's matched events: each distinct event name once, in
     * bulletin order. With no segments it is the bulletin's own catalog product, as before.
     *
     * @param  Collection<int, IngestedProductSegment>  $segments  any collection of segments (an Eloquent one or a plain one)
     * @return list<string>
     */
    public function eventNamesFor(Collection $segments): array
    {
        if ($segments->isEmpty()) {
            $name = $this->product?->name;

            return filled($name) ? [$name] : [];
        }

        return $segments->map(fn (IngestedProductSegment $segment): string => $segment->eventName())->unique()->values()->all();
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(TestProductScenario::class, 'test_product_scenario_id');
    }

    /**
     * The product's name for people: its catalog name, else its AWIPS ID, else its WMO header code, else a generic label.
     */
    public function displayTitle(): string
    {
        return $this->product?->name
            ?? match (true) {
                filled($this->awipsid) => strtoupper($this->awipsid),
                filled($this->ttaaii) => strtoupper($this->ttaaii),
                default => 'Weather product',
            };
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
