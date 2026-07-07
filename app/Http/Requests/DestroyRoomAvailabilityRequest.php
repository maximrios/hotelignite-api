<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DestroyRoomAvailabilityRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'room_availability_id' => ['required', 'integer', 'exists:room_availability,id'],
        ];
    }
}
