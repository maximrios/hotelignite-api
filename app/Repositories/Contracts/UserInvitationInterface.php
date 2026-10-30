<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\User;
use App\Models\UserInvitation;

interface UserInvitationInterface
{
    /**
     * Emite la invitación de acceso y encola el email de bienvenida. Revoca la
     * pendiente anterior del user, si la hay: vale sólo el link más reciente.
     * Si se llama dentro de una transacción, el email sale recién al commit.
     */
    public function issue(User $user, ?User $createdBy): UserInvitation;

    /** Revoca la invitación pendiente del user (ej. el staff le cargó la contraseña). */
    public function revokePending(User $user): void;

    /** Resuelve por token crudo. 404 si no existe (o si el token ya se rotó). */
    public function resolveByToken(string $token): UserInvitation;

    /**
     * Lo que ve la pantalla de activación. No marca la apertura: el GET lo
     * disparan también los escáneres de links de los clientes de correo.
     *
     * @return array<string, mixed>
     */
    public function describe(UserInvitation $invitation): array;

    /** Registra la primera apertura. La llama la página desde el navegador (JS). */
    public function markOpened(UserInvitation $invitation): void;

    /** Fija la contraseña elegida y da la invitación por aceptada. 410 si ya no vale. */
    public function accept(UserInvitation $invitation, string $password): User;

    /**
     * Manda el recordatorio a las invitaciones que llevan los días configurados
     * sin aceptar. Rota el token (el link viejo deja de andar) pero conserva el
     * vencimiento original. Devuelve cuántos mandó.
     */
    public function sendReminders(): int;
}
