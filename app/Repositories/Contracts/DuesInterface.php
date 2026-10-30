<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Client;
use App\Models\MemberLedgerEntry;
use App\Models\MembershipFee;
use App\Models\User;

interface DuesInterface
{
    /**
     * Estado de cuenta de cada socio: saldo, lo vencido impago, cuántas cuotas
     * debe y su categoría. Sin `$ids`, todos los que tienen categoría o asientos.
     *
     * @param  list<int>|null  $ids
     * @return array<int, array{balance: int, overdue_amount: int, overdue_count: int, oldest_due_on: ?string, status: string, fee: ?array}>
     */
    public function standing(Client $client, string $type, ?array $ids = null): array;

    /** Tablero de cobranza del mes: emitido, cobrado, por cobrar, antigüedad y deudores. */
    public function summary(Client $client, string $period): array;

    /** Socios con vínculo aceptado, con su categoría y estado de cuenta. */
    public function members(Client $client, string $type): array;

    /** Cuenta corriente de un socio: asientos con saldo acumulado. 404 si no está en el padrón. */
    public function account(Client $client, string $type, int $id): array;

    public function fees(Client $client): array;

    public function saveFee(Client $client, array $data, ?MembershipFee $fee = null): array;

    public function deleteFee(MembershipFee $fee): void;

    /**
     * Asigna (o quita, con `$feeId` null) la categoría a un lote de socios.
     *
     * @param  list<int>  $ids
     * @return array{updated: list<int>, skipped: list<int>}
     */
    public function assign(Client $client, string $type, array $ids, ?int $feeId): array;

    /** Cuántas cuotas saldrían al emitir el período, y por cuánto. */
    public function emissionPreview(Client $client, string $period): array;

    /** Emite la cuota del período a cada socio con categoría activa. Idempotente. */
    public function emit(Client $client, User $by, string $period): array;

    /** Registra un pago, un cargo manual o una bonificación. Devuelve la cuenta actualizada. */
    public function record(Client $client, User $by, string $type, int $id, array $data): array;

    /** Anula un asiento con otro de signo opuesto. Devuelve la cuenta actualizada. */
    public function reverse(Client $client, User $by, MemberLedgerEntry $entry): array;
}
