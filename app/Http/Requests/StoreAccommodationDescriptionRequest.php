<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccommodationDescriptionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'accommodation_id' => ['required', 'integer', 'exists:accommodations,id'],
            'language_id'      => ['required', 'string', 'size:2'],
            'introduction'     => ['nullable', 'string'],
            'description'      => ['nullable', 'string'],
            'enabled'          => ['nullable', 'boolean'],
        ];
    }
}
