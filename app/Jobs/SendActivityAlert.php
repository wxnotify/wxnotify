<?php

namespace App\Jobs;

use App\Enums\ActivityAlertType;
use App\Models\ActivityAlertRule;
use App\Models\User;
use App\Support\Apprise\AppriseApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class SendActivityAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $ruleId,
        public readonly ActivityAlertType $type,
        public readonly int $userId,
        public readonly Carbon $occurredAt,
    ) {}

    public function handle(): void
    {
        $rule = ActivityAlertRule::find($this->ruleId);
        $user = User::find($this->userId);

        if ($rule === null || $user === null || ! $rule->is_active) {
            return;
        }

        app(AppriseApiClient::class)->notify(
            url: $rule->apprise_target,
            body: "{$this->type->label()}: {$user->email} at {$this->occurredAt->toDayDateTimeString()}",
            title: 'WxNotify Activity Alert',
        );
    }
}
