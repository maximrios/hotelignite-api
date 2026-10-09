<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\PointOfInterest;
use App\Models\User;

/**
 * Contenido del catálogo turístico (eventos y puntos de interés) que cargan el
 * staff y los clients (docs/events-plan.md).
 *
 * Leer es libre para cualquier usuario del panel. Escribir: el staff todo, un
 * client sólo lo que cargó él. En `admin/v1` la escritura ya está detrás de
 * `platform`; esta policy es la que importa en `client-panel/v1`.
 */
class CatalogContentPolicy
{
    public function create(User $user): bool
    {
        return $user->isPlatform() || ($user->isClient() && $user->client_id !== null);
    }

    public function update(User $user, Event|PointOfInterest $content): bool
    {
        return $content->isEditableBy($user);
    }

    public function delete(User $user, Event|PointOfInterest $content): bool
    {
        return $content->isEditableBy($user);
    }
}
