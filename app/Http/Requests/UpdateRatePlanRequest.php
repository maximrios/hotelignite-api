<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRatePlanRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        $ratePlanId = $this->route('id');

        return [
            'name' => ['sometimes', 'string', 'min:3', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('rate_plans', 'code')->ignore($ratePlanId)],
            'cancellation' => ['nullable', 'string', Rule::in(['flexible', 'moderate', 'strict', 'non_refundable'])],
            'includes_breakfast' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
        ];
    }
}
