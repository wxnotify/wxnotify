<?php

namespace App\Http\Requests;

use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new channel from the chosen type's own setup-field descriptors, so the rules are the
 * ones the dashboard form applies (kept in step by ChannelApiParityTest).
 */
class StoreChannelRequest extends FormRequest
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
        $creatable = app(ChannelTypeRegistry::class)->creatableInApp($this->user());

        $rules = [
            'channel_type' => ['required', 'string', Rule::in($creatable->map->slug()->all())],
            'label' => ['nullable', 'string', 'max:255'],
        ];

        $type = $creatable->first(fn (ChannelType $type): bool => $type->slug() === $this->input('channel_type'));

        foreach ($type?->appSetupFields($this->user()) ?? [] as $field) {
            $rules[$field->key] = [$field->required ? 'required' : 'nullable', 'string', ...$field->rules];
        }

        return $rules;
    }
}
