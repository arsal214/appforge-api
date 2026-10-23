<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => [
                'required', 'string', 'email:rfc', 'max:190',
                Rule::unique('users', 'email')->ignore($this->user()->getKey()),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter your name.',
            'name.min' => 'Enter your name.',
            'email.required' => 'Enter a valid email address.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'That email address is already registered.',
        ];
    }
}
