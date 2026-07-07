<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DestroyRateRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'rate_id' => ['required', 'integer', 'exists:rates,id'],
        ];
    }
}
