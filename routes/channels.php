<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Channel authorization callbacks are registered here (Broadcast::channel(...)).

Broadcast::channel('live-products', fn (User $user): bool => true);
