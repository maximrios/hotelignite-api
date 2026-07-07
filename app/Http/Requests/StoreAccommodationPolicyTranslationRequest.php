<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccommodationPolicyTranslationRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'policy_id'   => ['required', 'integer', 'exists:accommodation_policies,id'],
            'language_id' => [
                'required', 'integer', 'exists:languages,id',
                \Illuminate\Validation\Rule::unique('accommodation_policy_translations')->where(fn($q) =>
                    $q->where('policy_id', $this->policy_id)
                ),
            ],
            'house_rules' => ['nullable', 'string'],
        ];
    }
}
