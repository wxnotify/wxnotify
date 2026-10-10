<?php

namespace App\Support\ChannelTypes\Concerns;

use App\Models\User;

trait NotCreatableInApp
{
    /**
     * @return null
     */
    public function appSetupFields(User $user): ?array
    {
        return null;
    }
}
