<?php

namespace App\Models;

use Database\Factories\IngestedProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['cccc', 'ttaaii', 'awipsid', 'nwws_id', 'wmo_ddhhmm', 'text', 'file_path', 'received_at'])]
class IngestedProduct extends Model
{
    /** @use HasFactory<IngestedProductFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
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
