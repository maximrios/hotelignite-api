<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `slug` no se expone: la tabla `services` no tiene esa columna (venía siempre
 * en `null`). Ver `docs/services-admin-crud-plan.md`.
 */
class ServiceResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon' => $this->icon,
            'type' => $this->type,
            'is_highlighted' => $this->is_highlighted,
            'enabled' => $this->enabled,
            // El `withCount('accommodations')` del repositorio evita el N+1; el
            // fallback cubre a quien arme el Resource sin contar —un `store`,
            // por ejemplo—, donde es una sola query y no una por fila.
            'qty' => $this->accommodations_count
                ?? ($this->relationLoaded('accommodations')
                    ? $this->accommodations->count()
                    : $this->accommodations()->count()),
        ];
    }
}
