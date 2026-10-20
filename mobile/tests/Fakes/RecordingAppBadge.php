<?php

namespace Tests\Fakes;

use App\Services\AppBadge;

/**
 * The suite's default icon-badge service: records every number the app sets instead of calling the
 * badge-count plugin (off a device that call goes through the dev bridge and tries a localhost
 * socket). AppBadgeTest builds the real service itself.
 */
class RecordingAppBadge extends AppBadge
{
    /** @var array<int, int> */
    public array $counts = [];

    public function set(int $count): void
    {
        $this->counts[] = max(0, $count);
    }

    public function last(): ?int
    {
        return $this->counts === [] ? null : $this->counts[array_key_last($this->counts)];
    }
}
