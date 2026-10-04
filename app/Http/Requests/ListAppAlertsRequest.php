<?php

namespace App\Http\Requests;

use App\Support\AppAlertCursor;
use Illuminate\Foundation\Http\FormRequest;

class ListAppAlertsRequest extends FormRequest
{
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
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'before' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (AppAlertCursor::decode((string) $value) === null) {
                    $fail('The cursor is not valid.');
                }
            }],
        ];
    }
}
