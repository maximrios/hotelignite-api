<?php

namespace App\Models\Concerns;

use App\Models\Accommodation;
use App\Models\User;

/**
 * Tenencia para los recursos que cuelgan de un Accommodation.
 *
 * Cada modelo declara únicamente su relación con el padre inmediato; la cadena
 * hasta el Accommodation se resuelve por recursión, así que Rate (rate_plan →
 * room_type → accommodation) no necesita saber más que su propio eslabón.
 */
trait BelongsToAccommodation
{
    /**
     * Relación hacia el padre inmediato en la cadena hasta Accommodation.
     * null = el modelo tiene su propia columna accommodation_id.
     */
    protected static function accommodationParent(): ?string
    {
        return null;
    }

    /**
     * Limita la consulta a las filas cuyo Accommodation dueño es visible para el usuario.
     */
    public function scopeVisibleTo($query, User $user)
    {
        if ($user->isPlatform()) {
            return $query;
        }

        $parent = static::accommodationParent();

        if ($parent !== null) {
            return $query->whereHas($parent, fn ($q) => $q->visibleTo($user));
        }

        return $query->whereIn(
            $query->getModel()->qualifyColumn('accommodation_id'),
            Accommodation::visibleTo($user)->select('accommodations.id')
        );
    }

    /**
     * El Accommodation dueño, subiendo por la cadena. null si algún eslabón falta.
     */
    public function owningAccommodation(): ?Accommodation
    {
        $parent = static::accommodationParent();

        return $parent === null
            ? $this->accommodation
            : $this->{$parent}?->owningAccommodation();
    }
}
