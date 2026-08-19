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
     * Padrón del client: asociados con estado derivado + invitaciones pendientes.
     *
     * @return array<string, mixed>
     */
    public function associates(int $clientId): array;
}
