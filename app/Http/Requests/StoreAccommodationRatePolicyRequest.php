<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccommodationRatePolicyRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'accommodation_id' => ['required', 'integer', 'exists:accommodations,id'],
            'rate_type'        => [
                'required',
                Rule::in(['flexible', 'semi_flexible', 'non_refundable']),
                Rule::unique('accommodation_rate_policies')->where(fn($q) =>
                    $q->where('accommodation_id', $this->accommodation_id)
                ),
            ],
            'cancel_days'      => ['nullable', 'integer', 'min:0', 'max:365'],
            'cancel_penalty'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'no_show_penalty'  => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
