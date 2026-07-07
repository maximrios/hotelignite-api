<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccommodationDescriptionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'language_id'  => ['sometimes', 'string', 'size:2'],
            'introduction' => ['nullable', 'string'],
            'description'  => ['nullable', 'string'],
            'enabled'      => ['nullable', 'boolean'],
        ];
    }
}
