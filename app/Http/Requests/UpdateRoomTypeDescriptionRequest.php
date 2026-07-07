<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRoomTypeDescriptionRequest extends FormRequest
{

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'descriptions' => ['required', 'array', 'min:1'],
            'descriptions.*.language_id' => ['required', 'string', 'size:2'],
            'descriptions.*.name' => ['required', 'string', 'min:3'],
            'descriptions.*.description' => ['required', 'string'],
        ];
    }

    public function messages()
    {
        return [
            'descriptions.required' => 'At least one description is required.',
            'descriptions.array' => 'descriptions must be an array.',
            'descriptions.min' => 'At least one description must be provided.',
            'descriptions.*.language_id.required' => 'Each description must have a language_id.',
            'descriptions.*.language_id.size' => 'language_id must be a 2-character ISO code.',
            'descriptions.*.name.required' => 'Each description must have a name.',
            'descriptions.*.name.min' => 'Each name must be at least 3 characters.',
            'descriptions.*.description.required' => 'Each description must have a description.',
        ];
    }
}

