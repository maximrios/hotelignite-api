<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DestroyRatePlanRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'rate_plan_id' => ['required', 'integer', 'exists:rate_plans,id'],
        ];
    }
}
