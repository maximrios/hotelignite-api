<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRoomAvailabilityRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'available'           => ['sometimes', 'integer', 'min:0'],
            'total'               => ['sometimes', 'integer', 'min:0'],
            'closed'              => ['nullable', 'boolean'],
            'closed_to_arrival'   => ['nullable', 'boolean'],
            'closed_to_departure' => ['nullable', 'boolean'],
        ];
    }
}
