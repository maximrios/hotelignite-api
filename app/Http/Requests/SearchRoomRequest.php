<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchRoomRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'accommodation_id' => ['sometimes', 'integer', 'exists:accommodations,id'],
            'room_type_id' => ['sometimes', 'integer', 'exists:room_types,id'],
            'status' => ['sometimes', 'string', Rule::in(['available', 'occupied', 'maintenance', 'blocked', 'checkout'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
