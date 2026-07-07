<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccommodationPolicyOldRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'accommodation_id' => ['required', 'integer', 'exists:accommodations,id'],
            'policies' => ['required', 'array', 'min:1'],
            'policies.*.policy_id' => ['required', 'integer', 'exists:policies,id'],
            'policies.*.language_id' => ['required', 'string', 'size:2'],
            'policies.*.description' => ['required', 'string'],
        ];
    }
}
