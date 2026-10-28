<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryRoomRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'number' => ['sometimes', 'required', 'string', 'max:20'],
            'floor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:200'],
            'room_type_id' => ['sometimes', 'integer'],
            // Cambiar de tipo con reservas por delante exige confirmarlo.
            'confirm_type_change' => ['sometimes', 'boolean'],
            'out_of_service' => ['sometimes', 'boolean'],
            'maintenance_note' => ['nullable', 'string', 'max:255'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['string', 'max:30'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('number'))) {
            $this->merge(['number' => trim($this->input('number'))]);
        }
    }

    public function messages()
    {
        return ['number.required' => 'Ingresá el número de la habitación.'];
    }
}
