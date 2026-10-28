<?php

namespace App\Http\Requests\Housekeeping;

use Illuminate\Foundation\Http\FormRequest;

/** Alta (exige `accommodation_id` y `name`) y edición (nombre y/o activo) del personal. */
class StaffRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $creating = $this->isMethod('POST');

        return [
            'accommodation_id' => [$creating ? 'required' : 'sometimes', 'integer'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:80'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages()
    {
        return ['name.required' => 'Ingresá el nombre.'];
    }
}
