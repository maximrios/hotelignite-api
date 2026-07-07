<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRoomTypeServiceRequest extends FormRequest
{

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['required', 'integer', 'exists:services,id'],
        ];
    }

    public function messages()
    {
        return [
            'service_ids.required' => 'At least one service_id is required.',
            'service_ids.array' => 'service_ids must be an array.',
            'service_ids.min' => 'At least one service must be provided.',
            'service_ids.*.exists' => 'One or more service_ids do not exist.',
        ];
    }
}

