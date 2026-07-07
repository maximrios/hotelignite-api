<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoomRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'room_type_id' => ['sometimes', 'integer', 'exists:room_types,id'],
            'accommodation_id' => ['sometimes', 'integer', 'exists:accommodations,id'],
            'number' => ['sometimes', 'string', 'max:20'],
            'floor' => ['nullable', 'integer', 'min:0', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(['available', 'occupied', 'maintenance', 'blocked', 'checkout'])],
            'notes' => ['nullable', 'string'],
        ];
    }
}
