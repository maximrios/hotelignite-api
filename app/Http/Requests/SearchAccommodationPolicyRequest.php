<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchAccommodationPolicyRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'accommodation_id' => ['sometimes', 'integer', 'exists:accommodations,id'],
            'limit'            => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset'           => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
