<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class GrantOwner extends Command
{
    protected $signature = 'app:grant-owner {email : Email of an existing user to promote to owner}';

    protected $description = 'Promote an existing user to owner (and staff). The only way to create the first owner.';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user with that email address.');

            return self::FAILURE;
        }

        $user->forceFill(['is_owner' => true])->save();
        $this->info("{$user->email} is now an owner.");

        return self::SUCCESS;
    }
}
