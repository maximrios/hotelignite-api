<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StorePointOfInterestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return PointOfInterestRules::for(null, $this->input('source'));
    }

    /**
     * Mensajes de la regla que no se deduce del nombre del campo.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'poi_category_id.exists' => 'La categoría tiene que ser una subcategoría (no una categoría raíz).',
        ];
    }
}
