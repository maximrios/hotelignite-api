<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccommodationPolicyRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'accommodation_id' => ['required', 'integer', 'exists:accommodations,id', 'unique:accommodation_policies,accommodation_id'],
            'checkin_from'     => ['nullable', 'date_format:H:i'],
            'checkin_to'       => ['nullable', 'date_format:H:i'],
            'checkout_from'    => ['nullable', 'date_format:H:i'],
            'checkout_to'      => ['nullable', 'date_format:H:i'],
            'min_age'          => ['nullable', 'integer', 'min:0', 'max:100'],
            'allow_children'   => ['sometimes', 'boolean'],
            'children_max_age' => ['nullable', 'integer', 'min:0', 'max:17'],
            'allow_pets'       => ['sometimes', 'boolean'],
            'allow_smoking'    => ['sometimes', 'boolean'],
            'allow_parties'    => ['sometimes', 'boolean'],
            'payment_card'     => ['sometimes', 'boolean'],
            'payment_cash'     => ['sometimes', 'boolean'],
            'payment_transfer' => ['sometimes', 'boolean'],
            'payment_crypto'   => ['sometimes', 'boolean'],
        ];
    }
}
