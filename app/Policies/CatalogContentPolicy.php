<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\Experience;
use App\Models\PointOfInterest;
use App\Models\User;

/**
 * Contenido del catálogo turístico (eventos, puntos de interés y experiencias)
 * que cargan el staff y los clients (docs/events-plan.md,
 * docs/experiences-plan.md).
 *
 * Leer eventos y POI es libre para cualquier usuario del panel; las
 * experiencias son por client, así que en `admin/v1` hasta la lectura está
 * detrás de `platform`. Escribir: el staff todo, un client sólo lo que cargó él.
 * Esta policy es la que importa en `client-panel/v1`.
 */
class CatalogContentPolicy
{
    public function create(User $user): bool
    {
        return $user->isPlatform() || ($user->isClient() && $user->client_id !== null);
    }

    public function update(User $user, Event|Experience|PointOfInterest $content): bool
    {
        return $content->isEditableBy($user);
    }

    public function delete(User $user, Event|Experience|PointOfInterest $content): bool
    {
        return $content->isEditableBy($user);
    }
}
