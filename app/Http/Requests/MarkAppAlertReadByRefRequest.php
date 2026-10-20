<?php

namespace App\Http\Requests;

use App\Support\AlertRef;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `ref` is the alert group reference a push's tap link carried (AlertRef); anything not in its strict format is a 422.
 */
class MarkAppAlertReadByRefRequest extends FormRequest
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
            'ref' => ['required', 'string', 'max:'.AlertRef::MAX_LENGTH, 'regex:'.AlertRef::PATTERN],
        ];
    }
}
