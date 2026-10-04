<?php

namespace App\Http\Requests;

use App\Http\Controllers\Api\V1\AppTokenController;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ExchangeAppTokenRequest extends FormRequest
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
            'code' => ['required', 'string', 'size:64'],
            'code_verifier' => ['required', 'string', 'min:43', 'max:128'],
        ];
    }

    /**
     * A malformed request gets the same answer as a wrong code, so the response never says which check failed.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(AppTokenController::invalidGrant());
    }
}
