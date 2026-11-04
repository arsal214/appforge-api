<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'sourceUrl' => ['sometimes', 'nullable', 'string', 'url:http,https', 'max:2048'],

            'accentColor' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],

            'businessProfileId' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('business_profiles', 'id')->where(
                    'organization_id',
                    $this->user()?->organization_id,
                ),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.min' => 'Give the app a name.',
            'sourceUrl.url' => 'Enter a valid website address.',
            'accentColor.regex' => 'Pick a colour like #0f766e.',
            'businessProfileId.exists' => 'Those saved business details are not available.',
        ];
    }
}
