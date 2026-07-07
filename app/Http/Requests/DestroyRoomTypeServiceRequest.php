<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DestroyRoomTypeServiceRequest extends FormRequest
{

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'room_type_service_id' => ['required', 'integer', 'exists:room_type_services,id'],
        ];
    }
}

