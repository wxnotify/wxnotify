<?php

namespace App\Http\Requests;

use App\Support\AppAlertCursor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('wfo'))) {
            $this->merge(['wfo' => strtoupper($this->input('wfo'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'min:2', 'max:100'],
            'type' => ['nullable', 'integer', 'min:1'],
            'wfo' => ['nullable', 'string', Rule::exists('cwa_boundaries', 'cwa')],
            'period' => ['nullable', 'string', Rule::in(['hour', 'day', 'week', 'month'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'before' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (AppAlertCursor::decode((string) $value) === null) {
                    $fail('The cursor is not valid.');
                }
            }],
        ];
    }
}
