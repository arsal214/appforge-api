<?php

namespace App\Http\Requests;

use AppForge\Schema\Contract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePushCampaignRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Notification text is truncated hard by both platforms. These caps
            // are generous against the ~40/~150 characters actually shown, so a
            // customer is warned by the panel rather than surprised on a phone.
            'title' => ['required', 'string', 'min:1', 'max:120'],
            'body' => ['required', 'string', 'min:1', 'max:400'],

            // Tapping a notification runs an SDUI action — the same closed
            // vocabulary the shell already executes. A push payload therefore
            // cannot introduce behaviour the app does not already have, which
            // is the same argument that keeps configs safe.
            'action' => ['nullable', 'array'],
            'action.type' => [
                'required_with:action',
                Rule::in(Contract::vocabulary('actionTypes')),
            ],

            // `nullable` means send-later is optional; absent means draft.
            'scheduledFor' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
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
