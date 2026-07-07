<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRatePlanRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', 'unique:rate_plans,code'],
            'cancellation' => ['nullable', 'string', Rule::in(['flexible', 'moderate', 'strict', 'non_refundable'])],
            'includes_breakfast' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
        ];
    }
}
