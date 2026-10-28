<?php

namespace App\Http\Requests\Housekeeping;

use Illuminate\Foundation\Http\FormRequest;

class DndRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'enabled' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
            'staff_id' => ['nullable', 'integer'],
        ];
    }
}
