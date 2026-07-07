<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchRatePlanRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'room_type_id' => ['sometimes', 'integer', 'exists:room_types,id'],
            'enabled' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
