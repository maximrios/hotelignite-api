<?php

namespace App\Http\Requests\Housekeeping;

use Illuminate\Foundation\Http\FormRequest;

/** Tarea manual / otra (texto libre) sobre una habitación. */
class StoreTaskRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'accommodation_id' => ['required', 'integer'],
            'room_id' => ['required', 'integer'],
            'note' => ['required', 'string', 'max:500'],
            'staff_id' => ['nullable', 'integer'],
        ];
    }

    public function messages()
    {
        return ['note.required' => 'Contá qué hay que hacer.'];
    }
}
