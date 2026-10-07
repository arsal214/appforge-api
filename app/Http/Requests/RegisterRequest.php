<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Field names are camelCase throughout the request layer, matching the
 * response layer and the panel's zod schemas. Laravel keys `errors` by field
 * name and the panel maps those keys straight onto form inputs, so a snake_case
 * rule here would silently orphan the message.
 */
class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            // Normalise before the uniqueness check, or "Joe@x.com" and
            // "joe@x.com" both register and neither can log in reliably.
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'organizationName' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8), 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter your name.',
            'name.min' => 'Enter your name.',
            'organizationName.required' => 'Enter your business name.',
            'organizationName.min' => 'Enter your business name.',
            'email.required' => 'Enter a valid email address.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'That email address is already registered.',
            'password.required' => 'Use at least 8 characters.',
            'password.min' => 'Use at least 8 characters.',
        ];
    }
}
