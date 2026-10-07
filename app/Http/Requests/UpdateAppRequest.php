<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAppRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'sourceUrl' => ['sometimes', 'nullable', 'string', 'url:http,https', 'max:2048'],
            // The mock accepted any string here. It cannot: the column is
            // char(7) and the value is interpolated into a stylesheet and a
            // native theme. Constrain it to a six-digit hex colour at the
            // boundary rather than discovering the truncation in a build.
            'accentColor' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.min' => 'Give the app a name.',
            'sourceUrl.url' => 'Enter a valid website address.',
            'accentColor.regex' => 'Pick a colour like #0f766e.',
        ];
    }
}
