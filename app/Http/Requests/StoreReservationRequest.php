<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de una reserva desde el rack del PMS (walk-in / telefónica). Lo que
 * depende de datos cargados —habitación del alojamiento, solapamiento,
 * capacidad del tipo, huésped visible— lo valida el repositorio, con errores
 * por campo para que el form los pinte en su lugar.
 */
class StoreReservationRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('guest.email');

        if (is_string($email)) {
            $this->merge(['guest' => [...$this->input('guest', []), 'email' => trim(mb_strtolower($email)) ?: null]]);
        }
    }

    public function rules()
    {
        return [
            'accommodation_id' => ['required', 'integer'],
            'room_id' => ['required', 'integer'],
            'checkin_date' => ['required', 'date_format:Y-m-d'],
            'checkout_date' => ['required', 'date_format:Y-m-d', 'after:checkin_date'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],

            // Huésped existente (sugerido por el lookup) o uno nuevo.
            'guest_id' => ['nullable', 'integer'],
            'guest.first_name' => ['required_without:guest_id', 'nullable', 'string', 'max:255'],
            'guest.last_name' => ['required_without:guest_id', 'nullable', 'string', 'max:255'],
            'guest.email' => ['nullable', 'email', 'max:255'],
            'guest.phone' => ['nullable', 'string', 'max:50'],

            // Tarifa por noche en unidades de moneda. 0 sólo como cortesía explícita.
            'rate' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'complimentary' => ['boolean'],
            'rate_plan_id' => ['nullable', 'integer'],
            'channel_id' => ['nullable', 'integer', 'exists:channels,id'],

            'guarantee_type' => ['nullable', Rule::in(['credit_card', 'deposit', 'agency_voucher', 'none'])],
            'deposit_amount' => ['nullable', 'required_if:guarantee_type,deposit', 'numeric', 'gt:0'],
            'special_requests' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages()
    {
        return [
            'checkout_date.after' => 'La salida tiene que ser posterior a la llegada.',
            'guest.first_name.required_without' => 'Ingresá el nombre del huésped.',
            'guest.last_name.required_without' => 'Ingresá el apellido del huésped.',
            'guest.email.email' => 'El email no es válido.',
            'rate.required' => 'Ingresá la tarifa por noche.',
            'rate.min' => 'La tarifa no puede ser negativa.',
            'deposit_amount.required_if' => 'Ingresá el monto del depósito.',
            'adults.min' => 'Tiene que haber al menos un adulto.',
        ];
    }
}
