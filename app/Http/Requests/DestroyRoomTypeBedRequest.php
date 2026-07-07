<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DestroyRoomTypeBedRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'room_type_bed_id' => ['required', 'integer', 'exists:room_type_beds,id'],
        ];
    }
}
