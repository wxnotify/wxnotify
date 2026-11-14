<?php

namespace App\Console\Commands;

use App\Actions\Account\PurgeAccount;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

class PurgeDeletedAccounts extends Command
{
    protected $signature = 'accounts:purge-deleted';

    protected $description = 'Delete the accounts whose deletion grace period has passed';

    public function handle(PurgeAccount $purge): int
    {
        $purged = 0;

        User::query()
            ->whereNotNull('deletion_requested_at')
            ->where('deletion_requested_at', '<=', now()->subDays(config('wxnotify.account_deletion_days')))
            ->where('is_staff', false)
            ->where('is_owner', false)
            ->each(function (User $user) use ($purge, &$purged): void {
                try {
                    $purge($user);
                    $purged++;
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

        $this->info("Deleted {$purged} account(s).");

        return self::SUCCESS;
    }
}
