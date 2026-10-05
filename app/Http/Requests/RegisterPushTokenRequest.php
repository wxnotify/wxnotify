<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterPushTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The token rule refuses a raw APNs hex token (iOS getToken() can return it):
     * FCM registration tokens contain a colon, and FCM rejects a bare hex string.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:4096', 'not_regex:/^[0-9a-f]{64,}$/i'],
            'platform' => ['required', Rule::in(['ios', 'android'])],
        ];
    }
}
