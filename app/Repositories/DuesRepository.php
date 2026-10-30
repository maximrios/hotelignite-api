<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Client;
use App\Models\MemberFeeAssignment;
use App\Models\MemberLedgerEntry;
use App\Models\MembershipFee;
use App\Models\User;
use App\Repositories\Contracts\DuesInterface;
use App\Support\AssociateType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cuotas y cuenta corriente de los socios de un client. Ver la migración
 * `create_membership_dues_tables` para el modelo.
 *
 * El estado de cada socio se calcula, no se guarda. Los pagos se imputan solos
 * a la cuota más vieja (FIFO): no hay imputación manual. Un asiento anulado y su
 * anulación no participan del cálculo — se cancelan entre sí.
 */
class DuesRepository implements DuesInterface
{
    /** Desde cuántas cuotas vencidas impagas un socio pasa a "moroso". */
    public const DELINQUENT_AFTER = 3;

    public const STATUS_NO_FEE = 'sin_cuota';

    public const STATUS_CURRENT = 'al_dia';

    public const STATUS_OWES = 'debe';

    public const STATUS_DELINQUENT = 'moroso';

    private const MONTHS = [
        1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    // ── Estado de cuenta ────────────────────────────────────────────────

    public function standing(Client $client, string $type, ?array $ids = null): array
    {
        AssociateType::tables($type);
        $today = CarbonImmutable::today()->toDateString();

        $balances = $this->live($client, $type, $ids)
            ->select('member_id')
            ->selectRaw('SUM(amount) as balance')
            ->groupBy('member_id')
            ->pluck('balance', 'member_id');

        $overdue = collect($this->pendingCharges($client, $type, $ids))
            ->filter(fn (object $c) => $c->due_on < $today)
            ->groupBy('member_id');

        $fees = MemberFeeAssignment::query()
            ->join('membership_fees as f', 'f.id', '=', 'member_fee_assignments.membership_fee_id')
            ->where('member_fee_assignments.client_id', $client->id)
            ->where('member_type', $type)
            ->when($ids !== null, fn ($q) => $q->whereIn('member_id', $ids))
            ->get(['member_id', 'f.id', 'f.name', 'f.amount', 'f.active'])
            ->keyBy('member_id');

        $memberIds = $ids ?? $balances->keys()->merge($fees->keys())->unique()->values()->all();

        $out = [];
        foreach ($memberIds as $id) {
            $id = (int) $id;
            $late = $overdue->get($id, collect());
            $fee = $fees->get($id);
            $hasEntries = $balances->has($id);
            $count = $late->count();

            $out[$id] = [
                'balance' => (int) ($balances[$id] ?? 0),
                'overdue_amount' => (int) $late->sum('pending'),
                'overdue_count' => $count,
                'oldest_due_on' => $late->min('due_on'),
                'status' => match (true) {
                    $fee === null && ! $hasEntries => self::STATUS_NO_FEE,
                    $count === 0 => self::STATUS_CURRENT,
                    $count >= self::DELINQUENT_AFTER => self::STATUS_DELINQUENT,
                    default => self::STATUS_OWES,
                },
                'fee' => $fee === null ? null : [
                    'id' => (int) $fee->id,
                    'name' => $fee->name,
                    'amount' => (int) $fee->amount,
                    'active' => (bool) $fee->active,
                ],
            ];
        }

        return $out;
    }

    public function summary(Client $client, string $period): array
    {
        $type = AssociateType::ACCOMMODATION;
        [$from, $to] = $this->monthRange($period);
        $today = CarbonImmutable::today();

        $month = $this->live($client, $type)
            ->whereBetween('occurred_on', [$from, $to])
            ->selectRaw("COALESCE(SUM(amount) FILTER (WHERE kind = 'charge'), 0) as emitted")
            ->selectRaw("COALESCE(-SUM(amount) FILTER (WHERE kind = 'payment'), 0) as collected")
            ->first();

        $pending = collect($this->pendingCharges($client, $type));
        $late = $pending->filter(fn (object $c) => $c->due_on < $today->toDateString());

        $buckets = ['0_30' => 0, '31_60' => 0, '61_90' => 0, '90_plus' => 0];
        foreach ($late as $charge) {
            $days = CarbonImmutable::parse($charge->due_on)->diffInDays($today);
            $bucket = match (true) {
                $days <= 30 => '0_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => '90_plus',
            };
            $buckets[$bucket] += (int) $charge->pending;
        }

        $standing = $this->standing($client, $type);
        $debtorIds = collect($standing)->filter(fn (array $s) => $s['overdue_count'] > 0)->keys()->all();
        $names = $this->memberNames($client, $type, $debtorIds);

        $debtors = collect($debtorIds)
            ->map(fn (int $id) => ['entity_type' => $type, 'entity_id' => $id] + ($names[$id] ?? ['name' => "#{$id}", 'city' => null]) + $standing[$id])
            ->sortByDesc('overdue_amount')
            ->values();

        $members = $this->membersQuery($client, $type)->pluck('id')->map(fn ($id) => (int) $id);
        $withoutFee = $members->filter(fn (int $id) => ($standing[$id]['fee'] ?? null) === null)->count();

        return [
            'period' => $period,
            'emitted' => (int) $month->emitted,
            'collected' => (int) $month->collected,
            'outstanding' => (int) $pending->sum('pending'),
            'overdue' => (int) $late->sum('pending'),
            'aging' => $buckets,
            'members' => $members->count(),
            'members_without_fee' => $withoutFee,
            'members_with_debt' => $debtors->count(),
            'delinquent' => $debtors->where('status', self::STATUS_DELINQUENT)->count(),
            'delinquent_after' => self::DELINQUENT_AFTER,
            'debtors' => $debtors->all(),
            'emission' => $this->emissionPreview($client, $period),
        ];
    }

    public function members(Client $client, string $type): array
    {
        $rows = $this->membersQuery($client, $type)
            ->leftJoin('cities as c', 'c.id', '=', 'e.city_id')
            ->orderBy('e.name')
            ->addSelect(['e.name', 'c.name as city'])
            ->get();

        $standing = $this->standing($client, $type, $rows->pluck('id')->map(fn ($id) => (int) $id)->all());

        return $rows->map(fn (object $r) => [
            'entity_type' => $type,
            'entity_id' => (int) $r->id,
            'name' => $r->name,
            'city' => $r->city,
        ] + $standing[(int) $r->id])->values()->all();
    }

    public function account(Client $client, string $type, int $id): array
    {
        $member = $this->memberOrFail($client, $type, $id);

        $entries = MemberLedgerEntry::query()
            ->with('createdBy:id,name')
            ->where('client_id', $client->id)
            ->where('member_type', $type)
            ->where('member_id', $id)
            ->orderBy('occurred_on')
            ->orderBy('id')
            ->get();

        $reversed = $entries->whereNotNull('reverses_entry_id')->pluck('reverses_entry_id')->flip();
        $pending = collect($this->pendingCharges($client, $type, [$id]))->keyBy('id');

        $running = 0;
        $rows = $entries->map(function (MemberLedgerEntry $e) use (&$running, $reversed, $pending) {
            $running += $e->amount;

            return [
                'id' => $e->id,
                'kind' => $e->kind,
                'concept' => $e->concept,
                'period' => $e->period,
                'occurred_on' => $e->occurred_on->toDateString(),
                'due_on' => $e->due_on?->toDateString(),
                'debit' => max($e->amount, 0),
                'credit' => max(-$e->amount, 0),
                'balance' => $running,
                'method' => $e->method,
                'reference' => $e->reference,
                'reversed' => $reversed->has($e->id),
                'reverses_entry_id' => $e->reverses_entry_id,
                'pending' => $e->kind === MemberLedgerEntry::KIND_CHARGE ? (int) ($pending->get($e->id)?->pending ?? 0) : null,
                'created_by' => $e->createdBy?->name,
                'created_at' => $e->created_at?->toIso8601String(),
            ];
        });

        return [
            'member' => $member,
            'standing' => $this->standing($client, $type, [$id])[$id],
            'delinquent_after' => self::DELINQUENT_AFTER,
            'entries' => $rows->values()->all(),
        ];
    }

    // ── Categorías ──────────────────────────────────────────────────────

    public function fees(Client $client): array
    {
        return MembershipFee::where('client_id', $client->id)
            ->withCount('assignments')
            ->orderByDesc('active')
            ->orderBy('name')
            ->get()
            ->map(fn (MembershipFee $f) => $this->feePayload($f))
            ->all();
    }

    public function saveFee(Client $client, array $data, ?MembershipFee $fee = null): array
    {
        $fee ??= new MembershipFee(['client_id' => $client->id]);
        $fee->fill($data)->save();

        return $this->feePayload($fee->loadCount('assignments'));
    }

    public function deleteFee(MembershipFee $fee): void
    {
        abort_if(
            $fee->assignments()->exists(),
            409,
            'Hay socios en esta categoría. Pasalos a otra o desactivala en vez de borrarla.'
        );

        $fee->delete();
    }

    public function assign(Client $client, string $type, array $ids, ?int $feeId): array
    {
        ['pivot' => $pivot, 'key' => $key] = AssociateType::tables($type);

        if ($feeId !== null) {
            MembershipFee::where('client_id', $client->id)->whereKey($feeId)->firstOrFail();
        }

        $valid = DB::table($pivot)
            ->where('client_id', $client->id)
            ->whereIn($key, $ids)
            ->pluck($key)
            ->map(fn ($id) => (int) $id)
            ->all();

        DB::transaction(function () use ($client, $type, $valid, $feeId) {
            $query = MemberFeeAssignment::where('client_id', $client->id)
                ->where('member_type', $type)
                ->whereIn('member_id', $valid);

            if ($feeId === null) {
                $query->delete();

                return;
            }

            foreach ($valid as $id) {
                MemberFeeAssignment::updateOrCreate(
                    ['client_id' => $client->id, 'member_type' => $type, 'member_id' => $id],
                    ['membership_fee_id' => $feeId],
                );
            }
        });

        return ['updated' => $valid, 'skipped' => array_values(array_diff(array_map('intval', $ids), $valid))];
    }

    // ── Emisión mensual ─────────────────────────────────────────────────

    public function emissionPreview(Client $client, string $period): array
    {
        $candidates = $this->emissionCandidates($client, $period);

        return [
            'period' => $period,
            'label' => $this->periodLabel($period),
            'to_emit' => $candidates->count(),
            'amount' => (int) $candidates->sum('amount'),
            'already_emitted' => $this->live($client, AssociateType::ACCOMMODATION)
                ->where('kind', MemberLedgerEntry::KIND_CHARGE)
                ->where('period', $period)
                ->count(),
        ];
    }

    public function emit(Client $client, User $by, string $period): array
    {
        return DB::transaction(function () use ($client, $by, $period) {
            // Dos "Emitir" simultáneos del mismo client no duplican cuotas.
            DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [8810, $client->id]);

            $label = $this->periodLabel($period);
            $candidates = $this->emissionCandidates($client, $period);

            foreach ($candidates as $c) {
                MemberLedgerEntry::create([
                    'client_id' => $client->id,
                    'member_type' => $c->member_type,
                    'member_id' => $c->member_id,
                    'kind' => MemberLedgerEntry::KIND_CHARGE,
                    'concept' => "Cuota {$label} · {$c->fee_name}",
                    'amount' => $c->amount,
                    'period' => $period,
                    'occurred_on' => "{$period}-01",
                    'due_on' => sprintf('%s-%02d', $period, min((int) $c->due_day, 28)),
                    'membership_fee_id' => $c->fee_id,
                    'created_by_user_id' => $by->id,
                ]);
            }

            return ['emitted' => $candidates->count(), 'amount' => (int) $candidates->sum('amount')];
        });
    }

    // ── Asientos ────────────────────────────────────────────────────────

    public function record(Client $client, User $by, string $type, int $id, array $data): array
    {
        $this->memberOrFail($client, $type, $id);

        $kind = $data['kind'];
        $amount = (int) $data['amount'];
        $signed = $kind === MemberLedgerEntry::KIND_CHARGE ? $amount : -$amount;

        MemberLedgerEntry::create([
            'client_id' => $client->id,
            'member_type' => $type,
            'member_id' => $id,
            'kind' => $kind,
            'concept' => trim((string) ($data['concept'] ?? '')) ?: match ($kind) {
                MemberLedgerEntry::KIND_PAYMENT => 'Pago',
                MemberLedgerEntry::KIND_CREDIT => 'Bonificación',
                default => 'Cargo',
            },
            'amount' => $signed,
            'occurred_on' => $data['occurred_on'],
            'due_on' => $kind === MemberLedgerEntry::KIND_CHARGE ? ($data['due_on'] ?? $data['occurred_on']) : null,
            'method' => $kind === MemberLedgerEntry::KIND_PAYMENT ? $data['method'] : null,
            'reference' => $data['reference'] ?? null,
            'created_by_user_id' => $by->id,
        ]);

        return $this->account($client, $type, $id);
    }

    public function reverse(Client $client, User $by, MemberLedgerEntry $entry): array
    {
        abort_if((int) $entry->client_id !== $client->id, 404);
        abort_if($entry->kind === MemberLedgerEntry::KIND_REVERSAL, 422, 'Una anulación no se anula.');
        abort_if($entry->reversal()->exists(), 409, 'Este movimiento ya está anulado.');

        MemberLedgerEntry::create([
            'client_id' => $client->id,
            'member_type' => $entry->member_type,
            'member_id' => $entry->member_id,
            'kind' => MemberLedgerEntry::KIND_REVERSAL,
            'concept' => "Anulación: {$entry->concept}",
            'amount' => -$entry->amount,
            'occurred_on' => CarbonImmutable::today()->toDateString(),
            'reverses_entry_id' => $entry->id,
            'created_by_user_id' => $by->id,
        ]);

        return $this->account($client, $entry->member_type, $entry->member_id);
    }

    // ── Internos ────────────────────────────────────────────────────────

    /** Asientos que cuentan: sin las anulaciones ni lo anulado (se cancelan entre sí). */
    private function live(Client $client, string $type, ?array $ids = null)
    {
        return DB::table('member_ledger_entries as e')
            ->where('e.client_id', $client->id)
            ->where('e.member_type', $type)
            ->where('e.kind', '<>', MemberLedgerEntry::KIND_REVERSAL)
            ->whereNotExists(fn ($q) => $q->from('member_ledger_entries as r')->whereColumn('r.reverses_entry_id', 'e.id'))
            ->when($ids !== null, fn ($q) => $q->whereIn('e.member_id', $ids));
    }

    /**
     * Cargos con saldo impago, imputando lo pagado a los más viejos primero:
     * un cargo queda pendiente si el acumulado de cargos hasta él supera todo lo
     * pagado por ese socio.
     *
     * @return list<object{id: int, member_id: int, due_on: string, pending: int}>
     */
    private function pendingCharges(Client $client, string $type, ?array $ids = null): array
    {
        $bindings = [$client->id, $type];
        $memberFilter = '';
        if ($ids !== null) {
            if ($ids === []) {
                return [];
            }
            $memberFilter = 'AND e.member_id IN ('.implode(',', array_fill(0, count($ids), '?')).')';
            array_push($bindings, ...array_map('intval', $ids));
        }

        $rows = DB::select(<<<SQL
            WITH live AS (
                SELECT e.* FROM member_ledger_entries e
                 WHERE e.client_id = ? AND e.member_type = ? {$memberFilter}
                   AND e.kind <> 'reversal'
                   AND NOT EXISTS (SELECT 1 FROM member_ledger_entries r WHERE r.reverses_entry_id = e.id)
            ),
            paid AS (
                SELECT member_id, -SUM(amount) AS paid FROM live WHERE amount < 0 GROUP BY member_id
            ),
            ch AS (
                SELECT member_id, id, amount, COALESCE(due_on, occurred_on) AS due_on,
                       SUM(amount) OVER (PARTITION BY member_id ORDER BY COALESCE(due_on, occurred_on), id) AS cum
                  FROM live WHERE amount > 0
            )
            SELECT ch.id, ch.member_id, ch.due_on::text AS due_on,
                   LEAST(ch.amount, ch.cum - COALESCE(paid.paid, 0)) AS pending
              FROM ch LEFT JOIN paid ON paid.member_id = ch.member_id
             WHERE ch.cum > COALESCE(paid.paid, 0)
            SQL, $bindings);

        return array_map(function (object $r) {
            $r->id = (int) $r->id;
            $r->member_id = (int) $r->member_id;
            $r->pending = (int) $r->pending;

            return $r;
        }, $rows);
    }

    /**
     * Socios que pagan cuota: en el padrón, `active` y con el vínculo aceptado.
     * El id del asociado sale como `id`; los datos de la entidad, por `e`.
     */
    private function membersQuery(Client $client, string $type)
    {
        ['pivot' => $pivot, 'key' => $key, 'entity' => $entity] = AssociateType::tables($type);

        return DB::table("{$pivot} as pv")
            ->join("{$entity} as e", 'e.id', '=', "pv.{$key}")
            ->where('pv.client_id', $client->id)
            ->where('pv.status', 'active')
            ->whereNotNull('pv.agreed_at')
            ->whereNull('e.deleted_at')
            ->select("pv.{$key} as id");
    }

    private function emissionCandidates(Client $client, string $period): Collection
    {
        $type = AssociateType::ACCOMMODATION;
        ['pivot' => $pivot, 'key' => $key, 'entity' => $entity] = AssociateType::tables($type);

        return DB::table('member_fee_assignments as a')
            ->join('membership_fees as f', 'f.id', '=', 'a.membership_fee_id')
            ->join("{$pivot} as pv", fn ($j) => $j->on("pv.{$key}", '=', 'a.member_id')->on('pv.client_id', '=', 'a.client_id'))
            ->join("{$entity} as e", 'e.id', '=', 'a.member_id')
            ->where('a.client_id', $client->id)
            ->where('a.member_type', $type)
            ->where('f.active', true)
            ->where('pv.status', 'active')
            ->whereNotNull('pv.agreed_at')
            ->whereNull('e.deleted_at')
            ->whereNotExists(fn ($q) => $q->fromSub($this->live($client, $type)->select('e.*'), 'l')
                ->whereColumn('l.member_id', 'a.member_id')
                ->where('l.kind', MemberLedgerEntry::KIND_CHARGE)
                ->where('l.period', $period))
            ->get(['a.member_type', 'a.member_id', 'f.id as fee_id', 'f.name as fee_name', 'f.amount', 'f.due_day']);
    }

    /** @return array{entity_type: string, entity_id: int, name: string, city: ?string, agreed: bool} */
    private function memberOrFail(Client $client, string $type, int $id): array
    {
        ['pivot' => $pivot, 'key' => $key, 'entity' => $entity] = AssociateType::tables($type);

        $row = DB::table("{$pivot} as pv")
            ->join("{$entity} as e", 'e.id', '=', "pv.{$key}")
            ->leftJoin('cities as c', 'c.id', '=', 'e.city_id')
            ->where('pv.client_id', $client->id)
            ->where("pv.{$key}", $id)
            ->whereNull('e.deleted_at')
            ->first(['e.name', 'c.name as city', 'pv.agreed_at', 'pv.status']);

        // 404 y no 403: no confirmar que el asociado existe en otro padrón.
        abort_if($row === null, 404);

        return [
            'entity_type' => $type,
            'entity_id' => $id,
            'name' => $row->name,
            'city' => $row->city,
            'agreed' => $row->agreed_at !== null && $row->status === 'active',
        ];
    }

    /** @return array<int, array{name: string, city: ?string}> */
    private function memberNames(Client $client, string $type, array $ids): array
    {
        ['entity' => $entity] = AssociateType::tables($type);

        return DB::table("{$entity} as e")
            ->leftJoin('cities as c', 'c.id', '=', 'e.city_id')
            ->whereIn('e.id', $ids)
            ->get(['e.id', 'e.name', 'c.name as city'])
            ->mapWithKeys(fn (object $r) => [(int) $r->id => ['name' => $r->name, 'city' => $r->city]])
            ->all();
    }

    /** @return array{0: string, 1: string} */
    private function monthRange(string $period): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', "{$period}-01")->startOfDay();

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }

    private function periodLabel(string $period): string
    {
        [$y, $m] = array_map('intval', explode('-', $period));

        return self::MONTHS[$m]." {$y}";
    }

    private function feePayload(MembershipFee $f): array
    {
        return [
            'id' => $f->id,
            'name' => $f->name,
            'amount' => $f->amount,
            'due_day' => $f->due_day,
            'active' => $f->active,
            'members' => (int) ($f->assignments_count ?? 0),
        ];
    }
}
