<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DestroyAccommodationPolicyOldRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'accommodation_policy_id' => ['required', 'integer', 'exists:accommodation_policy_links,id'],
        ];
    }
}
