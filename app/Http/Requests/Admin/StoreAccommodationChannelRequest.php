<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Conectar el alojamiento a un canal del catálogo. Las reglas de negocio
 * —canal habilitado, sin client detrás, no repetido— viven en el repositorio,
 * que es donde está el contexto del alojamiento.
 */
class StoreAccommodationChannelRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'channel_id' => ['required', 'integer', 'exists:channels,id'],
            'enabled' => ['sometimes', 'boolean'],
            'commission_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'external_code' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
