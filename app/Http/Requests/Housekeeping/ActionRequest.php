<?php

namespace App\Http\Requests\Housekeeping;

use Illuminate\Foundation\Http\FormRequest;

/** Acciones rápidas (iniciar, limpia, inspeccionada, sucia, asignar): quién la hizo es opcional. */
class ActionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return ['staff_id' => ['nullable', 'integer']];
    }
}
