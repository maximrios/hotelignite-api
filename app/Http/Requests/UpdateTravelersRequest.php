<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\TravelerRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Edición del parte de viajeros de una reserva (antes o después del check-in). */
class UpdateTravelersRequest extends FormRequest
{
    use TravelerRules;

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'guest' => ['sometimes', 'array'],
            'guest.first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'guest.last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'guest.document_type' => ['sometimes', 'nullable', 'in:dni,passport,cuit,other'],
            'guest.document_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'guest.nationality' => ['sometimes', 'nullable', 'string', 'size:2'],
            ...$this->travelerRules(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $guest = $this->input('guest');
        if (is_array($guest) && isset($guest['nationality']) && is_string($guest['nationality'])) {
            $guest['nationality'] = strtoupper(trim($guest['nationality'])) ?: null;
            $this->merge(['guest' => $guest]);
        }
        $this->prepareTravelers();
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateTravelers($validator);
    }

    public function messages()
    {
        return [
            'guest.first_name.required' => 'Ingresá el nombre del huésped.',
            'guest.last_name.required' => 'Ingresá el apellido del huésped.',
            ...$this->travelerMessages(),
        ];
    }
}
