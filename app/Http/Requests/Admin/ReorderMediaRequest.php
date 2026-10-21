<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReorderMediaRequest extends FormRequest
{
    /** Dueño del evento o la experiencia antes que validación (staff: todos; client: los suyos). */
    public function authorize(): bool
    {
        $owner = $this->route('event') ?? $this->route('experience');

        return $owner !== null && $this->user()?->can('update', $owner) === true;
    }

    /** `ids` en el orden nuevo. Tienen que ser exactamente los de la galería (lo chequea el repositorio). */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
        ];
    }
}
