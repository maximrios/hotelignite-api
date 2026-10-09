<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReorderMediaRequest extends FormRequest
{
    /** Dueño del evento antes que validación (staff: todos; client: los suyos). */
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event !== null && $this->user()?->can('update', $event) === true;
    }

    /** `ids` en el orden nuevo. Tienen que ser exactamente los del evento (lo chequea el repositorio). */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
        ];
    }
}
