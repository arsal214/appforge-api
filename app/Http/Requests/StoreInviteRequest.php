<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInviteRequest extends FormRequest
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
            'email' => ['required', 'string', 'email:rfc', 'max:190', 'unique:users,email'],

            // 'owner' is deliberately absent. There is exactly one owner and it
            // is the account that registered; handing the keys to someone else
            // is an account transfer, not an invitation.
            'role' => ['required', Rule::in(array_diff(User::ROLES, ['owner']))],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Enter their email address.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'That email address already has an AppForge account. An account can only belong to one business.',
            'role.required' => 'Pick what they are allowed to do.',
            'role.in' => 'Pick what they are allowed to do.',
        ];
    }
}
