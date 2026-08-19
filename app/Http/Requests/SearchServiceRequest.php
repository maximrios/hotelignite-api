<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchServiceRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    /**
     * `slug` se fue con la columna que nunca existió; `limit`/`offset` también,
     * porque `ServiceRepository::search()` pagina con `per_page` y los ignoraba.
     */
    public function rules()
    {
        return [
            'name' => ['sometimes', 'string'],
            'enabled' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
