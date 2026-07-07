<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchAccommodationPolicyTranslationRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'policy_id'   => ['sometimes', 'integer', 'exists:accommodation_policies,id'],
            'language_id' => ['sometimes', 'integer', 'exists:languages,id'],
            'limit'       => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset'      => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
