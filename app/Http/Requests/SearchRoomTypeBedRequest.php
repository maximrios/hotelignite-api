<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchRoomTypeBedRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'room_type_id' => ['sometimes', 'integer', 'exists:room_types,id'],
            'type'         => ['sometimes', 'string', Rule::in(['single', 'double', 'twin', 'queen', 'king', 'sofa_bed', 'bunk'])],
            'limit'        => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset'       => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
