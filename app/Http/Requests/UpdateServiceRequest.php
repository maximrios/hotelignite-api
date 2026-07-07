<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceRequest extends FormRequest
{

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name'           => ['sometimes', 'string', 'min:3', 'max:255'],
            'slug'           => ['sometimes', 'string', 'max:255', 'unique:services,slug,' . $this->route('id')],
            'icon'           => ['sometimes', 'string', 'max:255'],
            'type'           => ['nullable', 'string', \Illuminate\Validation\Rule::in(['general', 'room', 'bathroom', 'accessibility', 'kitchen'])],
            'is_highlighted' => ['nullable', 'boolean'],
            'enabled'        => ['sometimes', 'boolean'],
        ];
    }
}




