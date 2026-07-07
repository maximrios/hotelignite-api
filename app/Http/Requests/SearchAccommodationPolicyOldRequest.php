<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchAccommodationPolicyOldRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'accommodation_id' => ['sometimes', 'integer', 'exists:accommodations,id'],
            'policy_id' => ['sometimes', 'integer', 'exists:policies,id'],
            'language_id' => ['sometimes', 'string', 'size:2'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
