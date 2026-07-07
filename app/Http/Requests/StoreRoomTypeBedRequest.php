<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoomTypeBedRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'type'         => ['required', 'string', Rule::in(['single', 'double', 'twin', 'queen', 'king', 'sofa_bed', 'bunk'])],
            'quantity'     => ['nullable', 'integer', 'min:1', 'max:10'],
        ];
    }
}
