<?php

namespace App\Http\Requests;

use AppForge\Schema\Contract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePushCampaignRequest extends FormRequest
{
    public function rules(): array
    {
        return [

            'title' => ['required', 'string', 'min:1', 'max:120'],
            'body' => ['required', 'string', 'min:1', 'max:400'],

            'action' => ['nullable', 'array'],
            'action.type' => [
                'required_with:action',
                Rule::in(Contract::vocabulary('actionTypes')),
            ],

            'scheduledFor' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Give the notification a title.',
            'body.required' => 'Write the message.',
            'action.type.in' => 'That is not something a notification can open.',
            'scheduledFor.after' => 'Pick a time in the future.',
        ];
    }
}
