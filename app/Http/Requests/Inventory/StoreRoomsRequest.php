<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/** Alta masiva de habitaciones de un tipo ("101 a 110, piso 1"). */
class StoreRoomsRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'room_type_id' => ['required', 'integer'],
            'numbers' => ['required', 'array', 'min:1', 'max:200'],
            'numbers.*' => ['required', 'string', 'max:20', 'distinct'],
            'floor' => ['nullable', 'integer', 'min:0', 'max:200'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['string', 'max:30'],
        ];
    }

    public function messages()
    {
        return [
            'numbers.required' => 'Indicá qué habitaciones crear.',
            'numbers.max' => 'Hasta 200 habitaciones por vez.',
            'numbers.*.distinct' => 'Hay números repetidos.',
        ];
    }
}
