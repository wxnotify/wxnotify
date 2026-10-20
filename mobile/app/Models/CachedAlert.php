<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One alert the app has seen: its feed entry, and its detail once opened. Only rows flagged
 * `in_feed` belong to the latest refreshed feed; the others exist for their detail. `read` is the
 * alert's read state as this phone last knew it (the server's flag, or a read made here that the
 * server may not have yet).
 */
#[Fillable(['alert_id', 'sort_at', 'list', 'detail', 'in_feed', 'read'])]
class CachedAlert extends Model
{
    protected $primaryKey = 'alert_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'sort_at' => 'datetime',
            'list' => 'array',
            'detail' => 'array',
            'in_feed' => 'boolean',
            'read' => 'boolean',
        ];
    }
}
