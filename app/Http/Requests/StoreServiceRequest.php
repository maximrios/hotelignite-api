<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            // `unique` es el punto del CRUD: sin esto el catálogo se llena de
            // duplicados ("Wifi" / "WiFi" / "Wi-Fi"), cada uno con su puñado de
            // alojamientos, y ningún filtro por servicio devuelve la lista
            // completa. La collation de MySQL es case-insensitive, así que
            // "WiFi" ya choca con "Wifi".
            'name' => ['required', 'string', 'min:3', 'max:255', 'unique:services,name'],
            'type' => ['required', Rule::in(['general', 'room', 'bathroom', 'accessibility', 'kitchen'])],
            // Alias público de la columna `ico`; el mapeo lo hace el modelo.
            'icon' => ['nullable', 'string', 'max:255'],
            'is_highlighted' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }
}
