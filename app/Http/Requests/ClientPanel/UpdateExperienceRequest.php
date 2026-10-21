<?php

namespace App\Http\Requests\ClientPanel;

use App\Http\Requests\Admin\UpdateExperienceRequest as AdminUpdateExperienceRequest;

/** Versión client de `Admin\UpdateExperienceRequest` (ver `StoreExperienceRequest`). */
class UpdateExperienceRequest extends AdminUpdateExperienceRequest
{
    /**
     * Dueño antes que validación: si no, editar algo ajeno con datos inválidos
     * devolvía 422 en vez de 403, confirmando que existe y mostrando las reglas.
     */
    public function authorize(): bool
    {
        $content = $this->route('experience');

        return $content !== null && $this->user()?->can('update', $content) === true;
    }

    public function rules(): array
    {
        return ExperienceClientFields::strip(parent::rules());
    }
}
