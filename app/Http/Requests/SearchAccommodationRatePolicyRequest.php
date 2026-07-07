<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchAccommodationRatePolicyRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'accommodation_id' => ['sometimes', 'integer', 'exists:accommodations,id'],
            'rate_type'        => ['sometimes', Rule::in(['flexible', 'semi_flexible', 'non_refundable'])],
            'limit'            => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset'           => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
