<?php

namespace App\Http\Requests\ClientPanel;

use App\Http\Requests\Admin\UpdateEventRequest as AdminUpdateEventRequest;

/** Versión client de `Admin\UpdateEventRequest`, sin los campos de curaduría. */
class UpdateEventRequest extends AdminUpdateEventRequest
{
    /**
     * Dueño antes que validación: si no, editar algo ajeno con datos inválidos
     * devolvía 422 en vez de 403, confirmando que existe y mostrando las reglas.
     */
    public function authorize(): bool
    {
        $content = $this->route('event');

        return $content !== null && $this->user()?->can('update', $content) === true;
    }

    public function rules(): array
    {
        return ClientContentFields::strip(parent::rules());
    }
}
