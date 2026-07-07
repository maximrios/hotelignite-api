<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DestroyAccommodationRatePolicyRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'accommodation_rate_policy_id' => ['required', 'integer', 'exists:accommodation_rate_policies,id'],
        ];
    }
}
