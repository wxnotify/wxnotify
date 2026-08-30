<?php

namespace App\Models;

use App\Enums\Tier;
use Illuminate\Database\Eloquent\Model;

class ChannelType extends Model
{
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'min_tier' => Tier::class,
        ];
    }
}
