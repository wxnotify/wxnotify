<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The body the geolocation plugin's beacon sends: {"points": [{latitude, longitude, accuracy?,
 * speed?, heading?, altitude?, timestamp}]}. Only the newest valid point is used.
 */
class StoreLocationPointsRequest extends FormRequest
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
            'points' => ['required', 'array', 'min:1', 'max:50'],
            'points.*' => ['array'],
            'points.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'points.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'points.*.accuracy' => ['nullable', 'numeric', 'min:0'],
            'points.*.speed' => ['nullable', 'numeric'],
            'points.*.heading' => ['nullable', 'numeric'],
            'points.*.altitude' => ['nullable', 'numeric'],
            'points.*.timestamp' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
        ];
    }
}
