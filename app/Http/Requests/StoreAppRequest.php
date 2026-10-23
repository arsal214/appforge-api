<?php

namespace App\Http\Requests;

use App\Support\Templates;
use AppForge\Schema\Contract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'templateId' => ['required', 'string', Rule::in(Templates::ids())],

            'sourceUrl' => ['present', 'nullable', 'string', 'url:http,https', 'max:2048'],
            'category' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(Contract::vocabulary('businessCategories')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Give the app a name.',
            'name.min' => 'Give the app a name.',
            'templateId.required' => 'Pick a starting point.',
            'templateId.in' => 'That starting point is not available.',
            'category.in' => 'That business type is not available.',
            'sourceUrl.url' => 'Enter a valid website address.',
        ];
    }
}
