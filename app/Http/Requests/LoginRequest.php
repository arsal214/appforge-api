<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Deliberately looser than registration: no `email:rfc`, no length
            // cap beyond sanity. A stricter rule here tells an attacker which
            // strings are not addresses we hold, and tells a legitimate user
            // with an unusual address that their password is fine when the
            // real problem is the email field.
            'email' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Enter a valid email address.',
            'password.required' => 'Enter your password.',
        ];
    }
}
