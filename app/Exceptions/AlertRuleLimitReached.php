<?php

namespace App\Exceptions;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Thrown by AlertRule's creating and updating hooks when a save would take the owner past
 * their plan's alert limit, or give a Free owner a second Outlook Report.
 */
class AlertRuleLimitReached extends RuntimeException
{
    public static function limit(int $limit): self
    {
        return new self("You have reached your plan's limit of {$limit} ".Str::plural('alert', $limit).'. Remove one or upgrade to add more.');
    }

    public static function outlook(): self
    {
        return new self('Your plan includes one Severe Weather Outlook Report, and you already have one.');
    }
}
