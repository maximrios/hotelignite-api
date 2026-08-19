<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Gestión de cuentas hoteleras: exclusiva de plataforma.
 *
 * Una `Account` es el tenant del que cuelgan los `Accommodation` y los `User` de
 * tipo `account`. Tocarla no es editar un registro más: es mover la frontera de
 * tenencia de todo lo que tiene abajo.
 *
 * El grupo /api/admin/v1 ya está detrás del middleware `platform`, así que esto
 * es defensa en profundidad — el middleware protege la ruta, la policy protege
 * el modelo (mismo criterio que `UserPolicy`).
 */
class AccountPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        // `delete` queda fuera del bypass a propósito: si before() devolviera
        // true para todo, el guard de delete() (no borrar una cuenta que todavía
        // tiene alojamientos o usuarios) nunca se evaluaría, porque el único que
        // llega hasta acá ya es platform. Mismo patrón que UserPolicy.
        if ($ability === 'delete') {
            return null;
        }

        return $user->isPlatform() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isPlatform();
    }

    public function view(User $user, Account $account): bool
    {
        return $user->isPlatform();
    }

    public function create(User $user): bool
    {
        return $user->isPlatform();
    }

    public function update(User $user, Account $account): bool
    {
        return $user->isPlatform();
    }

    /**
     * Solo se da de baja una cuenta vacía.
     *
     * `accommodations.account_id` y `users.account_id` son ids sueltos, sin FK
     * (el legacy los tiene como `int`, incompatible con el `bigint unsigned` de
     * `foreignId()`), así que la base no impide dejar huérfanos: borrar una
     * cuenta con alojamientos los deja apuntando a un id que ya no existe, y esos
     * alojamientos dejan de ser visibles para nadie sin que nada falle.
     *
     * La cuenta usa SoftDeletes, así que la fila sobrevive — pero `scopeVisibleTo`
     * filtra por `account_id`, no por la existencia de la cuenta, y el efecto
     * práctico es el mismo.
     */
    public function delete(User $user, Account $account): bool
    {
        if (! $user->isPlatform()) {
            return false;
        }

        return ! $account->accommodations()->exists()
            && ! $account->users()->exists();
    }
}
