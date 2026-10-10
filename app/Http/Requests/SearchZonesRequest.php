<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchZonesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A state or at least two characters of text is required, so the whole table is never returned.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'state' => ['nullable', 'string', 'size:2', 'alpha', 'required_without:q'],
            'q' => ['nullable', 'string', 'min:2', 'max:50', 'required_without:state'],
            'after' => ['nullable', 'string', 'max:6'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
