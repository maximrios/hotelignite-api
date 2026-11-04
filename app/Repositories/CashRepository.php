<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Accommodation;
use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\CashShiftBalance;
use App\Models\Reservation;
use App\Repositories\Concerns\ScopesToAccommodation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Caja del hotel (feature `cash_register`, plan Básico en adelante). Reglas
 * acordadas con hotelería el 2026-11-03 (ver `api/docs/pms-modules-roadmap.md`):
 *
 * - Un solo turno abierto por alojamiento. Se abre con fondo por moneda y se
 *   cierra contando el efectivo. La diferencia queda registrada como movimiento
 *   de ajuste, así el libro cuadra con lo contado.
 * - El arqueo es sólo efectivo, por moneda (ARS y USD no se suman nunca).
 * - El efectivo exige turno abierto. Los demás medios entran al turno abierto si
 *   hay uno, o sin turno si no: una seña por transferencia que llega de noche.
 * - El libro no se edita: un error se anula con un asiento inverso, que cae en el
 *   turno actual. Un turno cerrado nunca cambia.
 * - Todos los usuarios del hotel tienen los mismos permisos (no hay roles en la
 *   cuenta todavía); cada movimiento guarda quién lo cargó.
 * - El saldo de una reserva se calcula con sus cobros, no se guarda.
 */
class CashRepository
{
    use ScopesToAccommodation {
        visibleAccommodation as private visibleAccommodationOf;
        writableAccommodation as private writableAccommodationOf;
    }

    /** Desde cuántas horas un turno abierto se marca como olvidado. */
    public const STALE_AFTER_HOURS = 24;

    /** Hasta cuántos días atrás se puede fechar un cobro que no es efectivo. */
    public const BACKDATE_DAYS = 90;

    public const ENTRIES_PER_PAGE = 50;

    // La caja es del plan Básico en adelante: todo acceso pasa por estos dos.
    protected function visibleAccommodation($accommodationId): Accommodation
    {
        $accommodation = $this->visibleAccommodationOf($accommodationId);
        $accommodation->requireFeature('cash_register', 'La caja');

        return $accommodation;
    }

    protected function writableAccommodation($accommodationId): Accommodation
    {
        $accommodation = $this->writableAccommodationOf($accommodationId);
        $accommodation->requireFeature('cash_register', 'La caja');

        return $accommodation;
    }

    // ─── Estado de la caja ──────────────────────────────────────────────────────

    /** Pantalla principal: turno abierto (o null), sugerencia de fondo, categorías y cobros sin turno. */
    public function overview(int $accommodationId): array
    {
        $accommodation = $this->visibleAccommodation($accommodationId);
        $this->ensureCategories($accommodation);
        $open = $this->openShift($accommodation);

        $offShift = CashEntry::where('accommodation_id', $accommodation->id)
            ->whereNull('cash_shift_id')
            ->where('occurred_on', '>=', Reservation::today()->subDays(7)->toDateString())
            ->with(['category', 'createdBy', 'reservation.guest', 'reversal'])
            ->orderByDesc('id')
            ->get();

        return [
            'today' => Reservation::today()->toDateString(),
            'currencies' => CashEntry::CURRENCIES,
            'methods' => CashEntry::METHODS,
            'shift' => $open ? $this->shiftPayload($open) : null,
            'suggested_opening' => $open ? null : $this->suggestedOpening($accommodation),
            'categories' => $this->categoriesPayload($accommodation),
            'off_shift_entries' => $offShift->map(fn (CashEntry $e) => $this->entryPayload($e))->values(),
        ];
    }

    // ─── Turnos ─────────────────────────────────────────────────────────────────

    public function openShift(Accommodation $accommodation): ?CashShift
    {
        return CashShift::where('accommodation_id', $accommodation->id)->whereNull('closed_at')->first();
    }

    /** @param array{accommodation_id: int, balances?: list<array{currency: string, amount: int}>} $data */
    public function open(array $data, int $userId): array
    {
        $accommodation = $this->writableAccommodation($data['accommodation_id']);
        abort_if($this->openShift($accommodation) !== null, 409, 'Ya hay una caja abierta. Cerrala antes de abrir otra.');

        $opening = collect($data['balances'] ?? [])->pluck('amount', 'currency');

        try {
            $shift = DB::transaction(function () use ($accommodation, $opening, $userId) {
                $shift = CashShift::create([
                    'accommodation_id' => $accommodation->id,
                    'cash_register_id' => $this->register($accommodation)->id,
                    'opened_by_user_id' => $userId,
                    'opened_at' => now(),
                ]);

                foreach (CashEntry::CURRENCIES as $currency) {
                    $shift->balances()->create([
                        'currency' => $currency,
                        'opening_amount' => (int) ($opening[$currency] ?? 0),
                    ]);
                }

                return $shift;
            });
        } catch (UniqueConstraintViolationException) {
            // Dos aperturas simultáneas: el índice parcial deja pasar una sola.
            abort(409, 'Ya hay una caja abierta. Cerrala antes de abrir otra.');
        }

        return $this->shiftPayload($shift);
    }

    public function shift(int $shiftId): array
    {
        return $this->shiftPayload($this->findShift($shiftId, false));
    }

    /**
     * Cierra el turno con el efectivo contado por moneda. Guarda esperado,
     * contado y diferencia, y registra la diferencia como movimiento de ajuste.
     *
     * @param  array{counted: list<array{currency: string, amount: int}>, notes?: string|null}  $data
     */
    public function close(int $shiftId, array $data, int $userId): array
    {
        $shift = $this->findShift($shiftId, true);
        $counted = collect($data['counted'])->pluck('amount', 'currency');
        $notes = trim((string) ($data['notes'] ?? '')) ?: null;

        $shift = DB::transaction(function () use ($shift, $counted, $notes, $userId) {
            /** @var CashShift $locked */
            $locked = CashShift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->isOpen(), 409, 'La caja ya está cerrada.');

            $expected = $this->expectedCash($locked);
            $differences = [];
            foreach ($locked->balances as $balance) {
                if (! $counted->has($balance->currency)) {
                    throw ValidationException::withMessages([
                        'counted' => "Ingresá el efectivo contado en {$balance->currency}.",
                    ]);
                }
                $differences[$balance->currency] = (int) $counted[$balance->currency] - $expected[$balance->currency];
            }

            if ($notes === null && collect($differences)->contains(fn (int $d) => $d !== 0)) {
                throw ValidationException::withMessages([
                    'notes' => 'Hay diferencia en el arqueo: explicá qué pasó.',
                ]);
            }

            $accommodation = Accommodation::findOrFail($locked->accommodation_id);
            $this->ensureCategories($accommodation);

            foreach ($locked->balances as $balance) {
                $difference = $differences[$balance->currency];
                $balance->update([
                    'expected_amount' => $expected[$balance->currency],
                    'counted_amount' => (int) $counted[$balance->currency],
                    'difference' => $difference,
                ]);

                if ($difference !== 0) {
                    CashEntry::create([
                        'accommodation_id' => $locked->accommodation_id,
                        'cash_shift_id' => $locked->id,
                        'cash_category_id' => $this->systemCategory(
                            $accommodation,
                            $difference > 0 ? CashCategory::ADJUSTMENT_IN : CashCategory::ADJUSTMENT_OUT,
                        )->id,
                        'direction' => $difference > 0 ? CashCategory::IN : CashCategory::OUT,
                        'amount' => abs($difference),
                        'currency' => $balance->currency,
                        'method' => CashEntry::METHOD_CASH,
                        'concept' => $difference > 0 ? 'Sobrante de arqueo' : 'Faltante de arqueo',
                        'occurred_on' => Reservation::today()->toDateString(),
                        'created_by_user_id' => $userId,
                    ]);
                }
            }

            $locked->update([
                'closed_at' => now(),
                'closed_by_user_id' => $userId,
                'closing_notes' => $notes,
            ]);

            return $locked;
        });

        return $this->shiftPayload($shift->fresh());
    }

    /** Turnos del alojamiento, del más nuevo al más viejo. */
    public function shifts(int $accommodationId, int $page): array
    {
        $accommodation = $this->visibleAccommodation($accommodationId);

        $paginator = CashShift::where('accommodation_id', $accommodation->id)
            ->with(['balances', 'openedBy', 'closedBy'])
            ->orderByDesc('opened_at')
            ->paginate(20, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (CashShift $s) => $this->shiftHeader($s))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    // ─── Movimientos ────────────────────────────────────────────────────────────

    /**
     * Registra un ingreso o egreso. Con la misma `idempotency_key` devuelve el
     * movimiento ya cargado (doble clic, reintento de red).
     *
     * @param  array<string, mixed>  $data
     */
    public function record(array $data, int $userId): array
    {
        $accommodation = $this->writableAccommodation($data['accommodation_id']);
        $this->ensureCategories($accommodation);

        $key = $data['idempotency_key'] ?? null;
        if ($key !== null && ($existing = $this->byKey($accommodation, $key)) !== null) {
            return $this->entryPayload($existing);
        }

        $category = CashCategory::where('accommodation_id', $accommodation->id)->find($data['category_id']);
        if ($category === null || ! $category->active || ! $category->isManual()) {
            throw ValidationException::withMessages(['category_id' => 'Elegí una categoría válida.']);
        }

        $method = (string) $data['method'];
        $currency = (string) $data['currency'];
        $shift = $this->openShift($accommodation);
        $today = Reservation::today();

        if ($method === CashEntry::METHOD_CASH) {
            abort_if($shift === null, 409, 'Abrí la caja para registrar movimientos en efectivo.');
            $occurredOn = $today;
        } else {
            $occurredOn = isset($data['occurred_on']) ? Carbon::parse($data['occurred_on']) : $today;
            if ($occurredOn->gt($today) || $occurredOn->lt($today->copy()->subDays(self::BACKDATE_DAYS))) {
                throw ValidationException::withMessages([
                    'occurred_on' => 'La fecha no puede ser futura ni de hace más de '.self::BACKDATE_DAYS.' días.',
                ]);
            }
        }

        [$reservationId, $reservationAmount] = $this->reservationLink($accommodation, $data, $currency);

        $concept = trim((string) ($data['concept'] ?? '')) ?: null;
        if ($concept === null && $reservationId === null && in_array($category->code, CashCategory::NEEDS_CONCEPT, true)) {
            throw ValidationException::withMessages(['concept' => 'Contá de qué se trata el movimiento.']);
        }

        try {
            $entry = CashEntry::create([
                'accommodation_id' => $accommodation->id,
                'cash_shift_id' => $shift?->id,
                'cash_category_id' => $category->id,
                'reservation_id' => $reservationId,
                'direction' => $category->direction,
                'amount' => (int) $data['amount'],
                'currency' => $currency,
                'method' => $method,
                'reservation_amount' => $reservationAmount,
                'concept' => $concept,
                'reference' => trim((string) ($data['reference'] ?? '')) ?: null,
                'occurred_on' => $occurredOn->toDateString(),
                'idempotency_key' => $key,
                'created_by_user_id' => $userId,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Carrera con la misma clave: el otro request ya lo guardó.
            $entry = $this->byKey($accommodation, (string) $key);
            abort_if($entry === null, 409, 'No se pudo registrar el movimiento.');
        }

        return $this->entryPayload($entry->fresh(['category', 'createdBy', 'reservation.guest', 'reversal']));
    }

    /**
     * Anula un movimiento con un asiento inverso en el turno actual. El de
     * efectivo exige caja abierta (la plata sale o entra ahora).
     */
    public function reverse(int $entryId, string $reason, int $userId): array
    {
        $original = CashEntry::find($entryId);
        abort_if($original === null, 404);
        $accommodation = $this->writableAccommodation($original->accommodation_id);

        abort_if($original->reverses_entry_id !== null, 422, 'Una anulación no se anula: cargá el movimiento de nuevo.');
        abort_if($original->category?->group === CashCategory::GROUP_ADJUSTMENT, 422, 'El ajuste de arqueo no se anula: corregilo con otro movimiento.');
        abort_if($original->reversal()->exists(), 409, 'El movimiento ya está anulado.');

        $shift = $this->openShift($accommodation);
        abort_if($original->isCash() && $shift === null, 409, 'Abrí la caja para anular un movimiento en efectivo.');

        try {
            $reversal = CashEntry::create([
                'accommodation_id' => $original->accommodation_id,
                'cash_shift_id' => $shift?->id,
                'cash_category_id' => $original->cash_category_id,
                'reservation_id' => $original->reservation_id,
                'direction' => $original->direction === CashCategory::IN ? CashCategory::OUT : CashCategory::IN,
                'amount' => $original->amount,
                'currency' => $original->currency,
                'method' => $original->method,
                'reservation_amount' => $original->reservation_amount,
                'concept' => 'Anulación: '.$reason,
                'reference' => $original->reference,
                'occurred_on' => Reservation::today()->toDateString(),
                'reverses_entry_id' => $original->id,
                'created_by_user_id' => $userId,
            ]);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'El movimiento ya está anulado.');
        }

        return [
            'original' => $this->entryPayload($original->fresh(['category', 'createdBy', 'reservation.guest', 'reversal'])),
            'reversal' => $this->entryPayload($reversal->fresh(['category', 'createdBy', 'reservation.guest', 'reversal'])),
        ];
    }

    /**
     * Libro filtrado, paginado.
     *
     * @param  array<string, mixed>  $filters
     */
    public function entries(array $filters, int $page): array
    {
        $accommodation = $this->visibleAccommodation($filters['accommodation_id']);

        $paginator = $this->filtered($accommodation, $filters)
            ->with(['category', 'createdBy', 'reservation.guest', 'reversal'])
            ->orderByDesc('occurred_on')->orderByDesc('id')
            ->paginate(self::ENTRIES_PER_PAGE, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (CashEntry $e) => $this->entryPayload($e))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * Libro filtrado como filas para CSV (lo que pide el contador).
     *
     * @param  array<string, mixed>  $filters
     * @return \Generator<int, list<string>>
     */
    public function exportRows(array $filters): \Generator
    {
        $accommodation = $this->visibleAccommodation($filters['accommodation_id']);

        yield ['Fecha', 'Cargado', 'Tipo', 'Categoría', 'Concepto', 'Medio', 'Moneda', 'Monto', 'Reserva', 'Referencia', 'Usuario', 'Turno', 'Anula a'];

        $query = $this->filtered($accommodation, $filters)
            ->with(['category', 'createdBy', 'reservation'])
            ->orderBy('occurred_on')->orderBy('id');

        foreach ($query->lazy(500) as $e) {
            /** @var CashEntry $e */
            yield [
                $e->occurred_on->toDateString(),
                $e->created_at?->toDateTimeString() ?? '',
                $e->direction === CashCategory::IN ? 'Ingreso' : 'Egreso',
                (string) $e->category?->name,
                (string) $e->concept,
                $e->method,
                $e->currency,
                number_format($e->signedAmount() / 100, 2, '.', ''),
                (string) ($e->reservation?->confirmation_number ?? $e->reservation_id ?? ''),
                (string) $e->reference,
                (string) $e->createdBy?->name,
                (string) ($e->cash_shift_id ?? ''),
                (string) ($e->reverses_entry_id ?? ''),
            ];
        }
    }

    // ─── Reportes ───────────────────────────────────────────────────────────────

    /**
     * Resumen de un período por moneda: por medio de pago y por categoría, con lo
     * operativo separado de retiros, aportes y depósitos. Fecha del dinero.
     *
     * Una anulación se cuenta en la columna del movimiento que anula, con signo
     * negativo: así el par se cancela sin inflar ingresos y egresos.
     */
    public function report(int $accommodationId, string $from, string $to): array
    {
        $accommodation = $this->visibleAccommodation($accommodationId);

        $entries = CashEntry::where('accommodation_id', $accommodation->id)
            ->whereBetween('occurred_on', [$from, $to])
            ->with('category')
            ->get();

        $currencies = [];
        foreach ($entries->groupBy('currency') as $currency => $rows) {
            $byMethod = [];
            $byCategory = [];
            $totals = [
                'operating_in' => 0, 'operating_out' => 0,
                'treasury_in' => 0, 'treasury_out' => 0,
                'adjustment' => 0,
            ];

            foreach ($rows as $e) {
                /** @var CashEntry $e */
                $category = $e->category;
                $direction = $category?->direction ?? $e->direction;
                $group = $category?->group ?? CashCategory::GROUP_OPERATING;
                // Valor en la columna del sentido de la categoría.
                $value = $e->direction === $direction ? $e->amount : -$e->amount;

                $byMethod[$e->method] ??= ['method' => $e->method, 'in' => 0, 'out' => 0];
                $byMethod[$e->method][$direction] += $value;

                $byCategory[$e->cash_category_id] ??= [
                    'id' => $e->cash_category_id,
                    'name' => $category?->name,
                    'direction' => $direction,
                    'group' => $group,
                    'total' => 0,
                ];
                $byCategory[$e->cash_category_id]['total'] += $value;

                if ($group === CashCategory::GROUP_ADJUSTMENT) {
                    $totals['adjustment'] += $direction === CashCategory::IN ? $value : -$value;
                } else {
                    $totals[$group.'_'.$direction] += $value;
                }
            }

            $currencies[] = [
                'currency' => $currency,
                'totals' => $totals + [
                    'result' => $totals['operating_in'] - $totals['operating_out'],
                    'net' => $rows->sum(fn (CashEntry $e) => $e->signedAmount()),
                ],
                'by_method' => collect($byMethod)->sortBy(fn (array $m) => array_search($m['method'], CashEntry::METHODS, true))->values(),
                'by_category' => collect($byCategory)->sortBy([['group', 'asc'], ['direction', 'asc'], ['name', 'asc']])->values(),
            ];
        }

        $differences = CashShift::where('accommodation_id', $accommodation->id)
            ->whereNotNull('closed_at')
            ->whereBetween(DB::raw('DATE(closed_at)'), [$from, $to])
            ->whereHas('balances', fn ($q) => $q->where('difference', '!=', 0))
            ->count();

        return [
            'from' => $from,
            'to' => $to,
            'currencies' => collect($currencies)->sortBy(fn (array $c) => array_search($c['currency'], CashEntry::CURRENCIES, true))->values(),
            'shifts_with_difference' => $differences,
        ];
    }

    // ─── Reservas ───────────────────────────────────────────────────────────────

    /** Cobros de una reserva con total, pagado y saldo (en la moneda de la reserva). */
    public function reservation(string $reservationId): array
    {
        $reservation = Reservation::with('guest')->find($reservationId);
        abort_if($reservation === null, 404);
        $accommodation = $this->visibleAccommodation($reservation->accommodation_id);

        $entries = CashEntry::where('accommodation_id', $accommodation->id)
            ->where('reservation_id', $reservation->id)
            ->with(['category', 'createdBy', 'reversal'])
            ->orderBy('id')
            ->get();

        $total = $this->totalOf($reservation);
        $paid = $entries->sum(fn (CashEntry $e) => $this->inReservationCurrency($e, $reservation));

        return [
            'reservation_id' => $reservation->id,
            'currency' => $this->currencyOf($reservation),
            'total' => $total,
            'paid' => $paid,
            'balance' => $total === null ? null : $total - $paid,
            'declared_deposit' => $reservation->deposit_amount !== null ? (int) $reservation->deposit_amount : null,
            'entries' => $entries->map(fn (CashEntry $e) => $this->entryPayload($e))->values(),
        ];
    }

    /**
     * Reservas vigentes con saldo: alojados y llegadas de los próximos 7 días.
     * Lo que recepción tiene que cobrar.
     */
    public function receivables(int $accommodationId): array
    {
        $accommodation = $this->visibleAccommodation($accommodationId);
        $today = Reservation::today();

        $reservations = Reservation::where('accommodation_id', $accommodation->id)
            ->whereNull('cancelled_at')
            ->whereNull('no_show_at')
            ->whereNull('checked_out_at')
            ->where('status_id', '!=', Reservation::STATUS_PENDING)
            ->where('checkin_date', '<=', $today->copy()->addDays(7)->toDateString())
            ->where('checkout_date', '>=', $today->toDateString())
            ->with('guest')
            ->orderBy('checkin_date')
            ->get();

        $entries = CashEntry::where('accommodation_id', $accommodation->id)
            ->whereIn('reservation_id', $reservations->pluck('id'))
            ->get()
            ->groupBy('reservation_id');

        $rows = [];
        foreach ($reservations as $r) {
            $total = $this->totalOf($r);
            if ($total === null || $total <= 0) {
                continue;
            }
            $paid = collect($entries->get($r->id, []))->sum(fn (CashEntry $e) => $this->inReservationCurrency($e, $r));
            if ($total - $paid <= 0) {
                continue;
            }
            $rows[] = [
                'reservation' => $this->reservationRef($r),
                'checkin_date' => $r->checkin_date?->toDateString(),
                'checkout_date' => $r->checkout_date?->toDateString(),
                'status' => $r->derivedStatus(),
                'currency' => $this->currencyOf($r),
                'total' => $total,
                'paid' => $paid,
                'balance' => $total - $paid,
            ];
        }

        return ['data' => $rows];
    }

    // ─── Categorías ─────────────────────────────────────────────────────────────

    public function categories(int $accommodationId): array
    {
        $accommodation = $this->visibleAccommodation($accommodationId);
        $this->ensureCategories($accommodation);

        return ['data' => $this->categoriesPayload($accommodation, true)];
    }

    /** @param array{accommodation_id: int, name: string, direction: string} $data */
    public function storeCategory(array $data): array
    {
        $accommodation = $this->writableAccommodation($data['accommodation_id']);
        $this->ensureCategories($accommodation);

        $category = CashCategory::create([
            'accommodation_id' => $accommodation->id,
            'name' => trim($data['name']),
            'direction' => $data['direction'],
            'group' => CashCategory::GROUP_OPERATING,
            'sort_order' => (int) CashCategory::where('accommodation_id', $accommodation->id)->max('sort_order') + 1,
        ]);

        return $this->categoryPayload($category);
    }

    /** @param array{name?: string, active?: bool} $data */
    public function updateCategory(int $categoryId, array $data): array
    {
        $category = CashCategory::find($categoryId);
        abort_if($category === null, 404);
        $this->writableAccommodation($category->accommodation_id);

        abort_if($category->group === CashCategory::GROUP_ADJUSTMENT, 422, 'Las categorías de arqueo no se modifican.');
        abort_if(
            array_key_exists('active', $data) && ! $data['active'] && $category->isSystem(),
            422,
            'Las categorías del sistema no se desactivan: podés renombrarlas.'
        );

        $category->update(array_filter([
            'name' => isset($data['name']) ? trim($data['name']) : null,
            'active' => $data['active'] ?? null,
        ], fn ($v) => $v !== null));

        return $this->categoryPayload($category);
    }

    // ─── Internos ───────────────────────────────────────────────────────────────

    private function findShift(int $shiftId, bool $write): CashShift
    {
        $shift = CashShift::find($shiftId);
        abort_if($shift === null, 404);

        $write
            ? $this->writableAccommodation($shift->accommodation_id)
            : $this->visibleAccommodation($shift->accommodation_id);

        return $shift;
    }

    private function register(Accommodation $accommodation): CashRegister
    {
        return CashRegister::firstOrCreate(
            ['accommodation_id' => $accommodation->id, 'active' => true],
            ['name' => 'Recepción'],
        );
    }

    /** Siembra las categorías por defecto la primera vez (idempotente). */
    private function ensureCategories(Accommodation $accommodation): void
    {
        if (CashCategory::where('accommodation_id', $accommodation->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($accommodation) {
            foreach (CashCategory::DEFAULTS as $i => $category) {
                CashCategory::firstOrCreate(
                    $category['code'] !== null
                        ? ['accommodation_id' => $accommodation->id, 'code' => $category['code']]
                        : ['accommodation_id' => $accommodation->id, 'code' => null, 'name' => $category['name']],
                    $category + ['accommodation_id' => $accommodation->id, 'sort_order' => $i],
                );
            }
        });
    }

    private function systemCategory(Accommodation $accommodation, string $code): CashCategory
    {
        return CashCategory::where('accommodation_id', $accommodation->id)->where('code', $code)->firstOrFail();
    }

    private function byKey(Accommodation $accommodation, string $key): ?CashEntry
    {
        return CashEntry::where('accommodation_id', $accommodation->id)
            ->where('idempotency_key', $key)
            ->with(['category', 'createdBy', 'reservation.guest', 'reversal'])
            ->first();
    }

    /**
     * Valida el vínculo con una reserva. Si la moneda del cobro difiere de la de
     * la reserva, exige el equivalente (no hay tipo de cambio en el sistema).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string|null, 1: int|null}
     */
    private function reservationLink(Accommodation $accommodation, array $data, string $currency): array
    {
        if (empty($data['reservation_id'])) {
            return [null, null];
        }

        $reservation = Reservation::where('accommodation_id', $accommodation->id)->find($data['reservation_id']);
        if ($reservation === null) {
            throw ValidationException::withMessages(['reservation_id' => 'La reserva no es de este alojamiento.']);
        }

        if ($this->currencyOf($reservation) === $currency) {
            return [$reservation->id, null];
        }

        if (! isset($data['reservation_amount'])) {
            throw ValidationException::withMessages([
                'reservation_amount' => "La reserva está en {$this->currencyOf($reservation)}: ingresá cuánto equivale este cobro.",
            ]);
        }

        return [$reservation->id, (int) $data['reservation_amount']];
    }

    /** Efectivo esperado por moneda: fondo + ingresos − egresos en efectivo del turno. */
    private function expectedCash(CashShift $shift): array
    {
        $movements = CashEntry::where('cash_shift_id', $shift->id)
            ->where('method', CashEntry::METHOD_CASH)
            ->get()
            ->groupBy('currency')
            ->map(fn (Collection $rows) => $rows->sum(fn (CashEntry $e) => $e->signedAmount()));

        $expected = [];
        foreach ($shift->balances as $balance) {
            $expected[$balance->currency] = $balance->opening_amount + (int) ($movements[$balance->currency] ?? 0);
        }

        return $expected;
    }

    /** Fondo sugerido: lo contado al cierre del último turno. */
    private function suggestedOpening(Accommodation $accommodation): array
    {
        $last = CashShift::where('accommodation_id', $accommodation->id)
            ->whereNotNull('closed_at')
            ->latest('closed_at')
            ->with('balances')
            ->first();

        return collect(CashEntry::CURRENCIES)->map(fn (string $currency) => [
            'currency' => $currency,
            'amount' => (int) ($last?->balances->firstWhere('currency', $currency)?->counted_amount ?? 0),
        ])->values()->all();
    }

    /** @param array<string, mixed> $filters */
    private function filtered(Accommodation $accommodation, array $filters): Builder
    {
        return CashEntry::where('accommodation_id', $accommodation->id)
            ->when($filters['from'] ?? null, fn (Builder $q, string $v) => $q->where('occurred_on', '>=', $v))
            ->when($filters['to'] ?? null, fn (Builder $q, string $v) => $q->where('occurred_on', '<=', $v))
            ->when($filters['method'] ?? null, fn (Builder $q, string $v) => $q->where('method', $v))
            ->when($filters['currency'] ?? null, fn (Builder $q, string $v) => $q->where('currency', $v))
            ->when($filters['category_id'] ?? null, fn (Builder $q, int|string $v) => $q->where('cash_category_id', (int) $v))
            ->when($filters['shift_id'] ?? null, fn (Builder $q, int|string $v) => $q->where('cash_shift_id', (int) $v))
            ->when($filters['user_id'] ?? null, fn (Builder $q, int|string $v) => $q->where('created_by_user_id', (int) $v))
            ->when($filters['reservation_id'] ?? null, fn (Builder $q, string $v) => $q->where('reservation_id', $v));
    }

    private function totalOf(Reservation $reservation): ?int
    {
        if ($reservation->total_amount !== null) {
            return (int) $reservation->total_amount;
        }

        $legacy = $reservation->getAttribute('rate_total');

        return $legacy !== null ? (int) round((float) $legacy * 100) : null;
    }

    private function currencyOf(Reservation $reservation): string
    {
        return strtoupper((string) ($reservation->currency ?: 'ARS'));
    }

    /** Valor del movimiento en la moneda de la reserva, con signo (pago +, devolución −). */
    private function inReservationCurrency(CashEntry $entry, Reservation $reservation): int
    {
        $amount = $entry->currency === $this->currencyOf($reservation)
            ? $entry->amount
            : (int) ($entry->reservation_amount ?? 0);

        return $entry->direction === CashCategory::IN ? $amount : -$amount;
    }

    // ─── Payloads ───────────────────────────────────────────────────────────────

    private function shiftHeader(CashShift $shift): array
    {
        $shift->loadMissing(['balances', 'openedBy', 'closedBy']);
        $openedAt = $shift->opened_at;

        return [
            'id' => $shift->id,
            'status' => $shift->isOpen() ? 'open' : 'closed',
            'opened_at' => $openedAt?->toIso8601String(),
            'opened_by' => $shift->openedBy?->name,
            'closed_at' => $shift->closed_at?->toIso8601String(),
            'closed_by' => $shift->closedBy?->name,
            'closing_notes' => $shift->closing_notes,
            'stale' => $shift->isOpen() && $openedAt !== null && $openedAt->lt(now()->subHours(self::STALE_AFTER_HOURS)),
            'balances' => $shift->balances
                ->sortBy(fn (CashShiftBalance $b) => array_search($b->currency, CashEntry::CURRENCIES, true))
                ->map(fn (CashShiftBalance $b) => [
                    'currency' => $b->currency,
                    'opening' => $b->opening_amount,
                    'expected' => $b->expected_amount,
                    'counted' => $b->counted_amount,
                    'difference' => $b->difference,
                ])->values(),
        ];
    }

    /** Turno completo: encabezado, efectivo por moneda, totales por medio y movimientos. */
    private function shiftPayload(CashShift $shift): array
    {
        $header = $this->shiftHeader($shift);
        $entries = CashEntry::where('cash_shift_id', $shift->id)
            ->with(['category', 'createdBy', 'reservation.guest', 'reversal'])
            ->orderBy('id')
            ->get();

        $live = $shift->isOpen() ? $this->expectedCash($shift) : [];

        $header['balances'] = collect($header['balances'])->map(function (array $b) use ($entries, $live) {
            $cash = $entries->where('currency', $b['currency'])->where('method', CashEntry::METHOD_CASH);
            $b['cash_in'] = $cash->where('direction', CashCategory::IN)->sum('amount');
            $b['cash_out'] = $cash->where('direction', CashCategory::OUT)->sum('amount');
            if (array_key_exists($b['currency'], $live)) {
                $b['expected'] = $live[$b['currency']];
            }

            return $b;
        })->values();

        $header['by_method'] = $entries
            ->groupBy(fn (CashEntry $e) => $e->currency.'|'.$e->method)
            ->map(function (Collection $rows) {
                /** @var CashEntry $first */
                $first = $rows->first();

                return [
                    'currency' => $first->currency,
                    'method' => $first->method,
                    'in' => $rows->where('direction', CashCategory::IN)->sum('amount'),
                    'out' => $rows->where('direction', CashCategory::OUT)->sum('amount'),
                ];
            })
            ->sortBy(fn (array $m) => [array_search($m['currency'], CashEntry::CURRENCIES, true), array_search($m['method'], CashEntry::METHODS, true)])
            ->values();

        $header['entries'] = $entries->map(fn (CashEntry $e) => $this->entryPayload($e))->values();

        return $header;
    }

    private function entryPayload(CashEntry $entry): array
    {
        $entry->loadMissing(['category', 'createdBy', 'reservation.guest', 'reversal']);

        return [
            'id' => $entry->id,
            'direction' => $entry->direction,
            'amount' => $entry->amount,
            'currency' => $entry->currency,
            'method' => $entry->method,
            'category' => $entry->category ? [
                'id' => $entry->category->id,
                'name' => $entry->category->name,
                'group' => $entry->category->group,
            ] : null,
            'concept' => $entry->concept,
            'reference' => $entry->reference,
            'occurred_on' => $entry->occurred_on->toDateString(),
            'created_at' => $entry->created_at?->toIso8601String(),
            'created_by' => $entry->createdBy?->name,
            'shift_id' => $entry->cash_shift_id,
            'reservation' => $entry->reservation ? $this->reservationRef($entry->reservation) : null,
            'reservation_amount' => $entry->reservation_amount,
            'reverses_entry_id' => $entry->reverses_entry_id,
            'reversed_by_entry_id' => $entry->reversal?->id,
        ];
    }

    private function reservationRef(Reservation $reservation): array
    {
        $guest = $reservation->guest;

        return [
            'id' => $reservation->id,
            'confirmation_number' => $reservation->confirmation_number,
            'guest_name' => $guest ? trim($guest->first_name.' '.$guest->last_name) : null,
        ];
    }

    private function categoriesPayload(Accommodation $accommodation, bool $withInactive = false): Collection
    {
        return CashCategory::where('accommodation_id', $accommodation->id)
            ->when(! $withInactive, fn (Builder $q) => $q->where('active', true))
            ->orderBy('direction')->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn (CashCategory $c) => $this->categoryPayload($c))
            ->values();
    }

    private function categoryPayload(CashCategory $category): array
    {
        return [
            'id' => $category->id,
            'code' => $category->code,
            'name' => $category->name,
            'direction' => $category->direction,
            'group' => $category->group,
            'active' => $category->active,
            'system' => $category->isSystem(),
            'manual' => $category->isManual(),
        ];
    }
}
