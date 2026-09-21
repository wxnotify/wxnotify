<?php

namespace App\Models;

use Database\Factories\PipelineDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['feed', 'wmo_header_key', 'file_name', 'outcome', 'received_at'])]
class PipelineDelivery extends Model
{
    /** @use HasFactory<PipelineDeliveryFactory> */
    use HasFactory;

    public const OUTCOME_INGESTED = 'ingested';

    public const OUTCOME_DUPLICATE = 'duplicate';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }
}
