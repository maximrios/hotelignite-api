<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoomTypeBedRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'type'     => ['sometimes', 'string', Rule::in(['single', 'double', 'twin', 'queen', 'king', 'sofa_bed', 'bunk'])],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ];
    }
}
