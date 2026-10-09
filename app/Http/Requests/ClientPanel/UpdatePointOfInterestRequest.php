<?php

namespace App\Http\Requests\ClientPanel;

use App\Http\Requests\Admin\UpdatePointOfInterestRequest as AdminUpdatePointOfInterestRequest;

/** Versión client de `Admin\UpdatePointOfInterestRequest`, sin los campos de curaduría. */
class UpdatePointOfInterestRequest extends AdminUpdatePointOfInterestRequest
{
    /**
     * Dueño antes que validación: si no, editar algo ajeno con datos inválidos
     * devolvía 422 en vez de 403, confirmando que existe y mostrando las reglas.
     */
    public function authorize(): bool
    {
        $content = $this->route('poi');

        return $content !== null && $this->user()?->can('update', $content) === true;
    }

    public function rules(): array
    {
        return ClientContentFields::strip(parent::rules());
    }
}
