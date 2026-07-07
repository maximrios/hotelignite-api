<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccommodationTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'unique:accommodation_types,name'],
            'slug' => ['required', 'string', 'max:100', 'unique:accommodation_types,slug'],
        ];
    }
}
