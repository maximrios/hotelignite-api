<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccommodationAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gateado por AccommodationPolicy en el controller
    }

    public function rules(): array
    {
        return [
            'greeting' => ['nullable', 'string', 'max:500'],
            'allowed_origins' => ['nullable', 'array', 'max:10'],
            // Lo mismo que exige Colmexa: esquema, host y puerto, sin path. Un
            // origen con path nunca coincide con el header `Origin` del navegador.
            'allowed_origins.*' => ['string', 'max:255', 'regex:#^https?://[a-zA-Z0-9.\-]+(:\d{1,5})?$#'],
        ];
    }

    public function messages(): array
    {
        return [
            'allowed_origins.*.regex' => 'Cada sitio va como https://tuhotel.com, sin barra ni ruta al final.',
        ];
    }
}
