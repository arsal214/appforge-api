<?php

namespace App\Http\Requests;

use AppForge\Schema\Contract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The only unauthenticated write in the product that keeps what it is given, so
 * every bound here is the contract's own: a stranger's phone decides the
 * payload, and an unbounded one is a database bill.
 */
class StoreOrderRequest extends FormRequest
{
    public const MAX_QUANTITY = 99;

    public function rules(): array
    {
        return [
            'lines' => ['present', 'array', 'max:'.Contract::limit('maxItems')],
            'lines.*.itemId' => ['required', 'string', 'max:64'],
            'lines.*.title' => ['required', 'string', 'max:'.Contract::limit('maxShortText')],
            'lines.*.price' => ['nullable', 'string', 'max:40'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],

            'total' => ['nullable', 'string', 'max:40'],

            'details' => ['present', 'array', 'max:'.Contract::limit('maxDetailFields')],
            'details.*.label' => ['required', 'string', 'max:'.Contract::limit('maxLabel')],
            'details.*.value' => ['required', 'string', 'max:'.Contract::limit('maxShortText')],
            'details.*.kind' => ['nullable', Rule::in(Contract::vocabulary('detailKinds'))],

            'platform' => ['nullable', 'in:ios,android'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->input('lines') === [] && $this->input('details') === []) {
                    $validator->errors()->add(
                        'lines',
                        'An order needs either something in the basket or some details.',
                    );
                }
            },
        ];
    }
}
