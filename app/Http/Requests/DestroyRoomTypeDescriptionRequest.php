<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DestroyRoomTypeDescriptionRequest extends FormRequest
{

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'room_type_description_id' => ['required', 'integer', 'exists:room_type_descriptions,id'],
        ];
    }
}

