<?php

namespace App\Policies;

use App\Models\Accommodation;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Tenencia sobre Accommodation:
 * - platform: acceso total (bypass vía before()).
 * - account: CRUD sobre accommodations de su cuenta, y si tiene un acotamiento
 *   explícito (pivote accommodation_user), solo sobre esos.
 * - client: solo lectura, y solo de los accommodations relacionados (pivote).
 */
class AccommodationPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        return $user->isPlatform() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        // El listado se scopea en la query (scopeVisibleTo); acá solo se
        // permite el acceso al endpoint a account y client.
        return $user->isAccount() || $user->isClient();
    }

    public function view(User $user, Accommodation $accommodation): bool
    {
        if ($user->isAccount()) {
            return $user->account_id !== null
                && (int) $accommodation->account_id === (int) $user->account_id
                && $user->accommodationScopeAllows((int) $accommodation->id);
        }

        if ($user->isClient()) {
            return $user->client_id !== null
                && $accommodation->clients()
                    ->where('clients.id', $user->client_id)
                    ->exists();
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->isAccount() && $user->account_id !== null;
    }

    public function update(User $user, Accommodation $accommodation): bool
    {
        return $user->isAccount()
            && $user->account_id !== null
            && (int) $accommodation->account_id === (int) $user->account_id
            && $user->accommodationScopeAllows((int) $accommodation->id);
    }

    public function delete(User $user, Accommodation $accommodation): bool
    {
        return $this->update($user, $accommodation);
    }
}
