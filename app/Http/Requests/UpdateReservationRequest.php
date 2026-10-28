<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edición de una reserva: movimiento desde el rack (habitación y/o fechas) o
 * datos desde el detalle (ocupación, notas, contacto del huésped). Las reglas
 * que dependen del estado (in-house, por salir…) viven en el repositorio,
 * porque necesitan la reserva cargada.
 */
class UpdateReservationRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            // null = desasignar (vuelve a "Sin asignar").
            'room_id' => ['sometimes', 'nullable', 'integer'],
            'checkin_date' => ['sometimes', 'date_format:Y-m-d'],
            'checkout_date' => ['sometimes', 'date_format:Y-m-d'],

            'adults' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:20'],
            'special_requests' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'internal_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],

            // Datos del huésped de la reserva (no reasigna a otro huésped).
            'guest.first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'guest.last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'guest.email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'guest.phone' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('guest.email');

        if (is_string($email)) {
            $this->merge(['guest' => [...$this->input('guest', []), 'email' => trim(mb_strtolower($email)) ?: null]]);
        }
    }

    public function messages()
    {
        return [
            'guest.first_name.required' => 'Ingresá el nombre del huésped.',
            'guest.last_name.required' => 'Ingresá el apellido del huésped.',
            'guest.email.email' => 'El email no es válido.',
            'adults.min' => 'Tiene que haber al menos un adulto.',
        ];
    }
}
