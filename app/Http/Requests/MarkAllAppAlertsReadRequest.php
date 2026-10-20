<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `through` is the feed's `meta.as_of` the phone last loaded: only alerts created at or before it are marked read.
 * It may run at most MAX_CLOCK_SKEW_MINUTES ahead of the server clock; old app builds send none.
 */
class MarkAllAppAlertsReadRequest extends FormRequest
{
    public const int MAX_CLOCK_SKEW_MINUTES = 5;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'through' => ['nullable', 'string', 'date', 'before_or_equal:'.now()->addMinutes(self::MAX_CLOCK_SKEW_MINUTES)->toIso8601String()],
        ];
    }

    public function through(): ?CarbonImmutable
    {
        $through = $this->validated('through');

        return $through === null ? null : CarbonImmutable::parse($through);
    }
}
