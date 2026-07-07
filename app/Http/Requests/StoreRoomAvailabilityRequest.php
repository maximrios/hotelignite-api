<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoomAvailabilityRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'room_type_id'        => ['required', 'integer', 'exists:room_types,id'],
            'date_from'           => ['required', 'date'],
            'date_to'             => ['required', 'date', 'after_or_equal:date_from'],
            'available'           => ['required', 'integer', 'min:0'],
            'total'               => ['required', 'integer', 'min:0'],
            'closed'              => ['nullable', 'boolean'],
            'closed_to_arrival'   => ['nullable', 'boolean'],
            'closed_to_departure' => ['nullable', 'boolean'],
        ];
    }
}
