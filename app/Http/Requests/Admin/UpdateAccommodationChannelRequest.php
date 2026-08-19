<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Condiciones del alojamiento en un canal ya conectado. `channel_id` no se
 * acepta: el canal viene de la ruta, cambiarlo sería mover la conexión.
 */
class UpdateAccommodationChannelRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            // `nullable` a propósito: null = volver al default del catálogo.
            'commission_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'external_code' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
