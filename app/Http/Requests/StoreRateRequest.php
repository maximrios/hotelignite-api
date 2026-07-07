<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRateRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'rate_plan_id' => ['required', 'integer', 'exists:rate_plans,id'],
            'date_from'    => ['required', 'date'],
            'date_to'      => ['required', 'date', 'after_or_equal:date_from'],
            'price'        => ['required', 'integer', 'min:0'],
            'currency'     => ['nullable', 'string', 'size:3'],
            'min_stay'     => ['nullable', 'integer', 'min:1'],
            'max_stay'     => ['nullable', 'integer', 'min:1'],
        ];
    }
}
