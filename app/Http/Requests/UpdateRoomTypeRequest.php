<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoomTypeRequest extends FormRequest
{

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'size'               => ['nullable', 'numeric', 'min:1'],
            'max_occupancy'      => ['nullable', 'integer', 'min:1'],
            'standard_occupancy' => ['nullable', 'integer', 'min:1'],
            'quantity'           => ['nullable', 'integer', 'min:1'],
            'status'             => ['nullable', 'string', Rule::in(['active', 'inactive', 'maintenance'])],
            'slug'               => ['nullable', 'string', 'max:255', 'unique:room_types,slug,' . $this->route('id')],
            'accommodation_id'   => ['sometimes', 'integer', 'exists:accommodations,id'],
            'category_id'        => ['nullable', 'integer', 'exists:room_categories,id'],
        ];
    }
}







