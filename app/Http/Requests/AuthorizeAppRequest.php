<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class AuthorizeAppRequest extends FormRequest
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
        $rules = [
            'challenge' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'state' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,128}$/'],
            'device_name' => ['required', 'string', 'min:1', 'max:80', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'redirect' => ['required', 'string', Rule::in([config('wxnotify.app_auth.callback_uri')])],
        ];

        if ($this->isMethod('POST')) {
            $rules['decision'] = ['required', Rule::in(['allow', 'deny'])];
        }

        return $rules;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->view('app-auth.page', [
            'title' => 'This sign-in link is not valid',
            'message' => 'Go back to the WxNotify app and try signing in again.',
        ], 422));
    }
}
