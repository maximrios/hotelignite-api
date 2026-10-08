<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SearchPointOfInterestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `category_id` acepta raíz u hoja: con una raíz se listan todas sus hojas.
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'city_id' => ['sometimes', 'integer'],
            'category_id' => ['sometimes', 'integer'],
            'featured' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
            'has_location' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
