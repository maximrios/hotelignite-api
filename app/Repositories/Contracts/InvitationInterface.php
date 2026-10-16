<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Invitation;
use App\Models\User;

interface InvitationInterface
{
    /**
     * Emite invitaciones para un lote de filas desde un client. Cada fila lleva
     * un email obligatorio y, opcionales, el nombre del contacto y del
     * establecimiento. Deduplica las pendientes por (client, email), valida
     * formato y envía un mail por casilla.
     *
     * @param  array<int, array{email: string, contact_name?: ?string, accommodation_name?: ?string}>  $invitations
     * @return array{sent: array<int, string>, skipped: array<int, string>}
     */
    public function emit(int $clientId, User $createdBy, array $invitations): array;

    /**
     * Rota el token y renueva el vencimiento de una invitación (invalida el
     * anterior, emite uno nuevo). Reabre la invitación si estaba declinada.
     */
    public function resend(Invitation $invitation, User $user): Invitation;

    /**
     * Resuelve una invitación por su token crudo. 404 si no existe.
     */
    public function resolveByToken(string $token): Invitation;

    /**
     * Datos mínimos para el lado público (§10, item 6): caso, quién invita,
     * nombre del alojamiento si aplica. Sin emails ni datos de cuenta.
     *
     * @return array<string, mixed>
     */
    public function describe(Invitation $invitation): array;

    /**
     * Ejecuta el accept resolviendo el caso (A/B/C) contra el estado actual.
     * Devuelve el `User` resultante para que el controller emita el token.
     *
     * @param  array<string, mixed>  $data
     * @return array{case: string, accommodation_id: ?int, user: User}
     */
    public function accept(Invitation $invitation, ?User $authUser, array $data): array;

    /**
     * Rechaza una invitación (solo caso C).
     */
    public function decline(Invitation $invitation, ?User $authUser): void;

    /**
     * Invita a alojamientos que ya están en el padrón del client (asociados desde
     * el CRM). Si el alojamiento es un borrador, la invitación es para tomar la
     * ficha; si ya tiene dueño, para aceptar el vínculo. Mismo mail en los dos.
     *
     * `$email` sólo vale para un borrador sin email cargado: a un alojamiento con
     * dueño se le escribe siempre a su dueño.
     *
     * @param  array<int, int>  $accommodationIds
     * @return array{sent: array<int, int>, skipped: array<int, array{accommodation_id: int, reason: string}>}
     */
    public function emitForAccommodations(int $clientId, User $createdBy, array $accommodationIds, ?string $email = null): array;

    /**
     * Padrón del client: asociados con estado derivado (paginado y filtrable),
     * conteos por estado e invitaciones por email todavía sin alojamiento.
     *
     * @param  array{state?: ?string, search?: ?string, city_id?: ?int, page?: int, per_page?: int}  $filters
     * @return array<string, mixed>
     */
    public function associates(int $clientId, array $filters = []): array;

    /**
     * Solicitudes de vínculo pendientes para los alojamientos de la cuenta del
     * hotelero (bandeja del PMS).
     *
     * @return array<int, array<string, mixed>>
     */
    public function incomingFor(User $owner): array;

    /**
     * Acepta o rechaza una solicitud desde la bandeja del PMS. La sesión del
     * dueño reemplaza al token del mail: lo que se prueba es la propiedad del
     * alojamiento, no la casilla.
     */
    public function respondAsOwner(Invitation $invitation, User $owner, bool $accept): void;
}
