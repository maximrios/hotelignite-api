<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchRoomAvailabilityRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'room_type_id' => ['sometimes', 'integer', 'exists:room_types,id'],
            'date_from'    => ['sometimes', 'date'],
            'date_to'      => ['sometimes', 'date', 'after_or_equal:date_from'],
            'limit'        => ['sometimes', 'integer', 'min:1', 'max:366'],
            'offset'       => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
