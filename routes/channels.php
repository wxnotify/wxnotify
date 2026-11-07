<?php

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Broadcast;

// Channel authorization callbacks are registered here (Broadcast::channel(...)).

// Mirrors the dashboard panel's `verified` middleware (emailVerification() is required there): a
// user whose address is unverified is sent to the verification prompt and may not listen either.
Broadcast::channel('live-products', fn (User $user): bool => ! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail());
