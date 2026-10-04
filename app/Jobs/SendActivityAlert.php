<?php

namespace App\Jobs;

use App\Enums\ActivityAlertType;
use App\Models\ActivityAlertRule;
use App\Models\User;
use App\Support\Apprise\AppriseApiClient;
use App\Support\MessagePrefix;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Dispatched once per ActivityAlertRule matched by
 * App\Actions\Visibility\MatchActivityAlertRules. Takes ids rather than
 * model instances so a rule deleted or deactivated between dispatch and
 * processing is a silent, intentional no-op — not a failed job.
 */
class SendActivityAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $ruleId,
        public readonly ActivityAlertType $type,
        public readonly int $userId,
        public readonly Carbon $occurredAt,
        public readonly ?string $ipAddress = null,
    ) {}

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(AppriseApiClient $client): void
    {
        $rule = ActivityAlertRule::find($this->ruleId);
        $user = User::find($this->userId);

        if ($rule === null || $user === null || ! $rule->is_active) {
            return;
        }

        // A job queued before ipAddress existed unserializes with it uninitialized, not null.
        $ipAddress = $this->ipAddress ?? null;

        $delivered = $client->notify(
            url: $rule->apprise_target,
            body: "{$this->type->label()}: {$user->email} at {$this->occurredAt->toDayDateTimeString()} UTC".($ipAddress !== null ? " from {$ipAddress}" : ''),
            title: MessagePrefix::apply('WxNotify Activity Alert'),
        );

        if (! $delivered) {
            throw new RuntimeException("Apprise reported non-delivery for ActivityAlertRule [{$this->ruleId}].");
        }
    }

    public function failed(?Throwable $exception): void
    {
        logger()->error('SendActivityAlert exhausted its retries', [
            'rule_id' => $this->ruleId,
            'type' => $this->type->value,
            'user_id' => $this->userId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
