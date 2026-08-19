<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenencia de los recursos hijos de un Accommodation (rooms, room types,
 * reservas, tarifas, etc.). No define reglas propias: resuelve el alojamiento
 * dueño y delega en AccommodationPolicy, de modo que "quién puede qué" viva en
 * un solo lugar. Leer el hijo exige poder ver el padre; escribirlo, poder
 * editarlo — de ahí sale gratis que los clients sean read-only.
 *
 * El modelo debe usar el trait BelongsToAccommodation.
 */
class ChildOfAccommodationPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        return $user->isPlatform() ? true : null;
    }

    public function view(User $user, Model $child): bool
    {
        $accommodation = $child->owningAccommodation();

        return $accommodation !== null && $user->can('view', $accommodation);
    }

    /**
     * El alojamiento destino no se conoce acá; el repositorio lo resuelve contra
     * visibleTo() y autoriza 'update' sobre él antes de crear el hijo.
     */
    public function create(User $user): bool
    {
        return $user->isAccount() && $user->account_id !== null;
    }

    public function update(User $user, Model $child): bool
    {
        $accommodation = $child->owningAccommodation();

        return $accommodation !== null && $user->can('update', $accommodation);
    }

    public function delete(User $user, Model $child): bool
    {
        return $this->update($user, $child);
    }
}
