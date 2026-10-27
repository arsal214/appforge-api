<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AcceptInviteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'password' => ['required', 'string', Password::min(8), 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter your name.',
            'name.min' => 'Enter your name.',
            'password.required' => 'Use at least 8 characters.',
            'password.min' => 'Use at least 8 characters.',
        ];
    }
}
