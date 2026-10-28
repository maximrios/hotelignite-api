<?php

namespace App\Http\Requests\Housekeeping;

use Illuminate\Foundation\Http\FormRequest;

class ProblemRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
            'estimated_return' => ['nullable', 'date_format:Y-m-d'],
            'staff_id' => ['nullable', 'integer'],
        ];
    }

    public function messages()
    {
        return ['reason.required' => 'Contá cuál es el problema.'];
    }
}
