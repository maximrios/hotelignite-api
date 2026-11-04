<?php

namespace App\Http\Requests\Cash;

use Illuminate\Foundation\Http\FormRequest;

/** Anulación de un movimiento: el motivo es obligatorio. */
class ReverseEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:140'],
        ];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Contá por qué se anula.'];
    }
}
