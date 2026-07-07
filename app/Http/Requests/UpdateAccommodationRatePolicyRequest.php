<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccommodationRatePolicyRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'cancel_days'     => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'cancel_penalty'  => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'no_show_penalty' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
