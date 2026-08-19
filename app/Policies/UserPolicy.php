<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Gestión de usuarios: exclusiva de plataforma.
 *
 * El grupo /api/admin/v1 ya está detrás del middleware `platform`, así que esta
 * policy es defensa en profundidad — el middleware protege la ruta, la policy
 * protege el modelo (ver docs/users-crud-plan.md).
 */
class UserPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        // OJO: `delete` queda deliberadamente fuera del bypass. Si el before()
        // devolviera true para todo, los guards de delete() (no borrarse a uno
        // mismo, no borrar al último platform) nunca se evaluarían, porque el
        // único que llega hasta acá ya es platform.
        if ($ability === 'delete') {
            return null;
        }

        return $user->isPlatform() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isPlatform();
    }

    public function view(User $user, User $target): bool
    {
        return $user->isPlatform();
    }

    public function create(User $user): bool
    {
        return $user->isPlatform();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isPlatform();
    }

    public function delete(User $user, User $target): bool
    {
        if (! $user->isPlatform()) {
            return false;
        }

        // Nadie se da de baja a sí mismo: es el error más fácil de cometer desde
        // el CRM y deja al staff sin su propia sesión.
        if ((int) $user->id === (int) $target->id) {
            return false;
        }

        // Dar de baja al último platform deja la plataforma sin acceso a
        // /api/admin/v1, y no hay forma de revertirlo por API.
        if ($target->isPlatform() && $this->platformUserCount() <= 1) {
            return false;
        }

        return true;
    }

    private function platformUserCount(): int
    {
        return User::where('user_type', User::TYPE_PLATFORM)->count();
    }
}
