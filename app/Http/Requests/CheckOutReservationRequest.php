<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckOutReservationRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            // Sólo en salida anticipada: true mantiene el total, false lo recalcula
            // a las noches usadas. Lo exige el repositorio, que sabe si es anticipada.
            'charge_remaining' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
