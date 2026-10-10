<?php

namespace App\Http\Requests;

use App\Enums\LocationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSavedLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A mobile location may only change its name and update interval; a stationary one its name,
     * position (latitude and longitude together), address and timezone. Anything else sent is ignored.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $location = $this->user()->locations()->find($this->route('id')) ?? abort(404);

        if ($location->type === LocationType::Mobile) {
            return [
                'label' => ['required', 'string', 'max:255'],
                'update_interval_minutes' => ['required', Rule::in([5, 10, 15, 20, 25, 30])],
            ];
        }

        return [
            'label' => ['required', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'address' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'timezone:all'],
        ];
    }
}
