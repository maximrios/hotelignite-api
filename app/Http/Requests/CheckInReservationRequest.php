<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\TravelerRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Check-in desde el detalle: habitación (si no tenía o cambia), pax reales,
 * titular con documento y nacionalidad, acompañantes y vehículo (parte de
 * viajeros).
 */
class CheckInReservationRequest extends FormRequest
{
    use TravelerRules;

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'room_id' => ['sometimes', 'nullable', 'integer'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:20'],

            'guest.first_name' => ['required', 'string', 'max:255'],
            'guest.last_name' => ['required', 'string', 'max:255'],
            'guest.document_type' => ['required', 'in:dni,passport,cuit,other'],
            'guest.document_number' => ['required', 'string', 'max:30'],
            'guest.nationality' => ['required', 'string', 'size:2'],
            'guest.phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            ...$this->travelerRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateTravelers($validator);
    }

    protected function prepareForValidation(): void
    {
        $guest = $this->input('guest', []);

        if (is_array($guest)) {
            if (isset($guest['nationality']) && is_string($guest['nationality'])) {
                $guest['nationality'] = strtoupper(trim($guest['nationality']));
            }
            if (isset($guest['document_number']) && is_string($guest['document_number'])) {
                $guest['document_number'] = trim($guest['document_number']);
            }
            $this->merge(['guest' => $guest]);
        }
        $this->prepareTravelers();
    }

    public function messages()
    {
        return [
            'adults.required' => 'Indicá cuántos adultos llegaron.',
            'adults.min' => 'Tiene que haber al menos un adulto.',
            'guest.first_name.required' => 'Ingresá el nombre del huésped.',
            'guest.last_name.required' => 'Ingresá el apellido del huésped.',
            'guest.document_type.required' => 'Elegí el tipo de documento.',
            'guest.document_type.in' => 'Elegí el tipo de documento.',
            'guest.document_number.required' => 'Ingresá el número de documento.',
            'guest.nationality.required' => 'Elegí la nacionalidad.',
            'guest.nationality.size' => 'Elegí la nacionalidad.',
            ...$this->travelerMessages(),
        ];
    }
}
