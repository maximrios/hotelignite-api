<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReorderAccommodationImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'images'         => ['required', 'array', 'min:1'],
            'images.*.id'    => ['required', 'integer', 'exists:images,id'],
            'images.*.order' => ['required', 'integer', 'min:0'],
        ];
    }
}
