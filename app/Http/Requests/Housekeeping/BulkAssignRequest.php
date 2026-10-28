<?php

namespace App\Http\Requests\Housekeeping;

use Illuminate\Foundation\Http\FormRequest;

/** "Asignar todo el piso 2 a Marta": por piso o por lista de habitaciones. */
class BulkAssignRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'accommodation_id' => ['required', 'integer'],
            'staff_id' => ['required', 'integer'],
            'floor' => ['nullable', 'integer', 'min:0', 'max:200', 'required_without:room_ids'],
            'room_ids' => ['sometimes', 'array', 'min:1', 'max:500', 'required_without:floor'],
            'room_ids.*' => ['integer'],
        ];
    }

    /** Distingue "piso sin cargar" (floor: null) de "no mandó piso". */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated($key, $default);

        if ($key === null && ! isset($data['room_ids']) && $this->has('floor')) {
            $data['floor'] = $this->input('floor');
        }

        return $data;
    }
}
