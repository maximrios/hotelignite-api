<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Requests\Pricing\BulkRatesRequest;
use App\Http\Requests\Pricing\UpdateOccupancyPricingRequest;
use App\Http\Requests\Pricing\UpsertRatePlanRequest;
use App\Models\Accommodation;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Repositories\Concerns\ScopesToAccommodation;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Tarifas del PMS. Modelo acordado con hotelería:
 *
 * - Un plan base por hotel ("Tarifa base", BAR): el único precio cargado a mano,
 *   por tipo y por día, con las restricciones del día (estadía mínima, venta
 *   cerrada). Se crea solo la primera vez que se abre la grilla.
 * - Planes derivados del base: ajuste en % o monto por noche + política de
 *   cancelación + régimen. No se cargan día a día, se calculan.
 * - Planes a nivel hotel, asignados a uno o varios tipos (channel manager:
 *   tipo × plan).
 * - Ocupación: el base es para la ocupación estándar; se suma por adulto o
 *   menor adicional y se descuenta por uso single.
 *
 * Montos en la API en unidades de moneda; en la base, centavos.
 */
class PricingRepository
{
    use ScopesToAccommodation;

    private const MAX_GRID_DAYS = 62;

    // ─── Cotización ─────────────────────────────────────────────────────────────

    /**
     * Precio de una estadía en un tipo con un plan (o el base). Cada noche: precio
     * base del día → ajuste del plan → ocupación. Una noche sin precio deja la
     * cotización incompleta (el PMS pide cargar la tarifa a mano).
     *
     * @return array{plan: ?RatePlan, nightly: list<array{date: string, price: int}>, total: ?int, missing: list<string>, closed: list<string>, min_stay: ?int}
     */
    public function quoteStay(RoomType $type, ?RatePlan $plan, Carbon $checkin, Carbon $checkout, int $adults, int $children): array
    {
        $plan ??= $this->basePlan((int) $type->accommodation_id);
        $empty = ['plan' => $plan, 'nightly' => [], 'total' => null, 'missing' => [], 'closed' => [], 'min_stay' => null];

        if ($plan === null) {
            return [...$empty, 'missing' => $this->dates($checkin, $checkout)];
        }

        // Un derivado toma los precios de su base; un plan legacy, los suyos.
        $source = $plan->parent_id ?? $plan->id;
        $rates = Rate::where('rate_plan_id', $source)
            ->where('room_type_id', $type->id)
            ->where('date', '>=', $checkin->toDateString())
            ->where('date', '<', $checkout->toDateString())
            ->get()
            ->keyBy(fn (Rate $r) => $r->date->toDateString());

        $nightly = [];
        $missing = [];
        $closed = [];

        foreach ($this->dates($checkin, $checkout) as $date) {
            $rate = $rates->get($date);
            if ($rate === null) {
                $missing[] = $date;

                continue;
            }
            if ($rate->stop_sell) {
                $closed[] = $date;
            }
            $price = $plan->parent_id !== null ? $plan->derive($rate->price) : $rate->price;
            $nightly[] = ['date' => $date, 'price' => max(0, $price + $this->occupancyDelta($type, $adults, $children))];
        }

        // La estadía mínima es la del día de llegada.
        $minStay = $rates->get($checkin->toDateString())?->min_stay;

        return [
            'plan' => $plan,
            'nightly' => $nightly,
            'total' => $missing === [] ? array_sum(array_column($nightly, 'price')) : null,
            'missing' => $missing,
            'closed' => $closed,
            'min_stay' => $minStay !== null && $minStay > 1 ? $minStay : null,
        ];
    }

    /**
     * Ajuste por ocupación, en centavos por noche. Los adultos por encima de la
     * estándar pagan adicional de adulto; los menores que no entran en la
     * estándar, el de menor (o el de adulto si no se cargó). Un solo adulto en
     * una habitación para 2+ tiene el descuento de uso single.
     */
    private function occupancyDelta(RoomType $type, int $adults, int $children): int
    {
        $standard = (int) ($type->standard_occupancy ?? 0);
        if ($standard < 1) {
            return 0;
        }

        $extraAdults = max(0, $adults - $standard);
        $extraChildren = max(0, $children - max(0, $standard - $adults));
        $delta = $extraAdults * (int) ($type->extra_adult_price ?? 0)
            + $extraChildren * (int) ($type->extra_child_price ?? $type->extra_adult_price ?? 0);

        if ($adults === 1 && $children === 0 && $standard >= 2) {
            $delta -= (int) ($type->single_use_discount ?? 0);
        }

        return $delta;
    }

    /** Planes que se pueden vender en un tipo: el base y los asignados, activos. */
    public function plansFor(RoomType $type): Collection
    {
        return RatePlan::where('accommodation_id', $type->accommodation_id)
            ->where('enabled', true)
            ->where(fn ($q) => $q->where('is_base', true)
                ->orWhereHas('roomTypes', fn ($r) => $r->whereKey($type->id)))
            ->orderByDesc('is_base')
            ->orderBy('id')
            ->get();
    }

    // ─── Grilla ─────────────────────────────────────────────────────────────────

    public function grid(Request $request): array
    {
        $data = $request->validate([
            'accommodation_id' => ['required', 'integer'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after:from'],
        ]);
        $accommodation = $this->visibleAccommodation((int) $data['accommodation_id']);
        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);
        abort_if($from->diffInDays($to) > self::MAX_GRID_DAYS, 422, 'La grilla muestra hasta dos meses.');

        $base = $this->ensureBasePlan($accommodation);
        $types = RoomType::where('accommodation_id', $accommodation->id)->with('descriptions')->orderBy('id')->get();

        $cells = [];
        Rate::where('rate_plan_id', $base->id)
            ->whereIn('room_type_id', $types->pluck('id'))
            ->where('date', '>=', $from->toDateString())
            ->where('date', '<', $to->toDateString())
            ->get()
            ->each(function (Rate $rate) use (&$cells) {
                $cells[$rate->room_type_id][$rate->date->toDateString()] = [
                    'price' => $rate->price / 100,
                    'min_stay' => $rate->min_stay,
                    'stop_sell' => $rate->stop_sell,
                ];
            });

        return [
            'currency' => $this->currency($accommodation),
            'room_types' => $types->map(fn (RoomType $t) => [
                'id' => $t->id,
                'name' => $t->spanishName(),
                'code' => $t->code,
                'status' => $t->status ?? 'active',
                'standard_occupancy' => $t->standard_occupancy,
                'max_occupancy' => $t->max_occupancy,
                'extra_adult_price' => $this->units($t->extra_adult_price),
                'extra_child_price' => $this->units($t->extra_child_price),
                'single_use_discount' => $this->units($t->single_use_discount),
            ])->values(),
            'plans' => $this->plansPayload($accommodation->id),
            'cells' => (object) $cells,
        ];
    }

    /**
     * Edición masiva de la grilla: rango de fechas (inclusive) × días de la
     * semana × tipos. Precio fijo, o +/- % o monto sobre el que ya tiene (los días
     * sin precio se saltean); estadía mínima y venta cerrada, si vienen.
     *
     * @return array{updated: int, skipped: int}
     */
    public function bulk(BulkRatesRequest $request): array
    {
        $data = $request->validated();
        $accommodation = $this->writableAccommodation((int) $data['accommodation_id']);
        $base = $this->ensureBasePlan($accommodation);

        $typeIds = RoomType::where('accommodation_id', $accommodation->id)
            ->whereIn('id', $data['room_type_ids'])
            ->pluck('id');
        if ($typeIds->count() !== count(array_unique($data['room_type_ids']))) {
            throw ValidationException::withMessages(['room_type_ids' => 'Algún tipo no es de este alojamiento.']);
        }

        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);
        $weekdays = array_map('intval', $data['weekdays']);
        $dates = collect(CarbonPeriod::create($from, $to))
            ->filter(fn (Carbon $d) => in_array($d->dayOfWeek, $weekdays, true))
            ->map(fn (Carbon $d) => $d->toDateString())
            ->values();

        $mode = $data['price_mode'];
        $value = (float) ($data['price_value'] ?? 0);
        $existing = Rate::where('rate_plan_id', $base->id)
            ->whereIn('room_type_id', $typeIds)
            ->whereIn('date', $dates)
            ->get()
            ->keyBy(fn (Rate $r) => $r->room_type_id.'|'.$r->date->toDateString());

        $rows = [];
        $skipped = 0;
        $now = now();
        $currency = $this->currency($accommodation);

        foreach ($typeIds as $typeId) {
            foreach ($dates as $date) {
                $current = $existing->get($typeId.'|'.$date);
                $price = match ($mode) {
                    'set' => (int) round($value * 100),
                    'percent' => $current ? (int) round($current->price * (100 + $value) / 100) : null,
                    'amount' => $current ? max(0, $current->price + (int) round($value * 100)) : null,
                    default => $current?->price,
                };

                // Sin precio no hay fila que restringir: se saltea.
                if ($price === null) {
                    $skipped++;

                    continue;
                }

                $rows[] = [
                    'rate_plan_id' => $base->id,
                    'room_type_id' => $typeId,
                    'date' => $date,
                    'price' => $price,
                    'currency' => $currency,
                    'min_stay' => array_key_exists('min_stay', $data) && $data['min_stay'] !== null
                        ? (int) $data['min_stay']
                        : ($current->min_stay ?? 1),
                    'stop_sell' => array_key_exists('stop_sell', $data) && $data['stop_sell'] !== null
                        ? (bool) $data['stop_sell']
                        : ($current->stop_sell ?? false),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                Rate::upsert($chunk, ['rate_plan_id', 'room_type_id', 'date'], ['price', 'currency', 'min_stay', 'stop_sell', 'updated_at']);
            }
        });

        return ['updated' => count($rows), 'skipped' => $skipped];
    }

    /** Adicionales por ocupación de un tipo. */
    public function updateOccupancy(int $typeId, UpdateOccupancyPricingRequest $request): array
    {
        $type = RoomType::visibleTo($this->tenant())->find($typeId);
        abort_if($type === null, 404);
        Gate::authorize('update', Accommodation::findOrFail($type->accommodation_id));

        $data = $request->validated();
        $type->update([
            'extra_adult_price' => $this->cents($data['extra_adult_price'] ?? null),
            'extra_child_price' => $this->cents($data['extra_child_price'] ?? null),
            'single_use_discount' => $this->cents($data['single_use_discount'] ?? null),
        ]);

        return [
            'id' => $type->id,
            'extra_adult_price' => $this->units($type->extra_adult_price),
            'extra_child_price' => $this->units($type->extra_child_price),
            'single_use_discount' => $this->units($type->single_use_discount),
        ];
    }

    // ─── Planes ─────────────────────────────────────────────────────────────────

    public function storePlan(UpsertRatePlanRequest $request): array
    {
        $data = $request->validated();
        $accommodation = $this->writableAccommodation((int) $data['accommodation_id']);
        // Free: sólo la tarifa base. Planes derivados desde el Básico.
        $accommodation->requireFeature('rate_management', 'Los planes de tarifa');
        $base = $this->ensureBasePlan($accommodation);
        $this->assertPlanData($accommodation->id, $data);

        DB::transaction(function () use ($data, $accommodation, $base) {
            $plan = RatePlan::create([
                'accommodation_id' => $accommodation->id,
                'parent_id' => $base->id,
                ...$this->planColumns($data),
            ]);
            $plan->roomTypes()->sync($data['room_type_ids']);
        });

        return $this->plansPayload($accommodation->id);
    }

    public function updatePlan(int $id, UpsertRatePlanRequest $request): array
    {
        $plan = $this->writablePlan($id);
        $data = $request->validated();
        if (! $plan->is_base) {
            Accommodation::findOrFail($plan->accommodation_id)->requireFeature('rate_management', 'Los planes de tarifa');
        }
        $this->assertPlanData((int) $plan->accommodation_id, $data, $plan);

        DB::transaction(function () use ($plan, $data) {
            $columns = $this->planColumns($data);
            if ($plan->is_base) {
                // El base no deriva de nada y se vende en todos los tipos.
                unset($columns['adjustment_type'], $columns['adjustment_value']);
                $columns['enabled'] = true;
            }
            $plan->update($columns);
            if (! $plan->is_base) {
                $plan->roomTypes()->sync($data['room_type_ids']);
            }
        });

        return $this->plansPayload((int) $plan->accommodation_id);
    }

    /** Con reservas no se borra (las reservas lo referencian): se desactiva. */
    public function destroyPlan(int $id): array
    {
        $plan = $this->writablePlan($id);
        abort_if($plan->is_base, 422, 'La tarifa base no se puede eliminar.');

        if (Reservation::where('rate_plan_id', $plan->id)->exists()) {
            $plan->update(['enabled' => false]);
        } else {
            $plan->delete();
        }

        return $this->plansPayload((int) $plan->accommodation_id);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    public function basePlan(int $accommodationId): ?RatePlan
    {
        return RatePlan::where('accommodation_id', $accommodationId)->where('is_base', true)->first();
    }

    private function ensureBasePlan(Accommodation $accommodation): RatePlan
    {
        return $this->basePlan($accommodation->id) ?? RatePlan::create([
            'accommodation_id' => $accommodation->id,
            'name' => 'Tarifa base',
            'code' => $this->freeCode($accommodation->id, 'BAR'),
            'is_base' => true,
            'cancellation' => 'flexible',
            'meal_plan' => 'ro',
            'enabled' => true,
        ]);
    }

    /** BAR, o BAR2… si un plan legacy ya lo usa. */
    private function freeCode(int $accommodationId, string $code): string
    {
        $candidate = $code;
        for ($i = 2; RatePlan::where('accommodation_id', $accommodationId)->where('code', $candidate)->exists(); $i++) {
            $candidate = $code.$i;
        }

        return $candidate;
    }

    private function plansPayload(int $accommodationId): array
    {
        return RatePlan::where('accommodation_id', $accommodationId)
            ->with('roomTypes:id')
            ->orderByDesc('is_base')
            ->orderBy('id')
            ->get()
            ->map(fn (RatePlan $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'code' => $p->code,
                'is_base' => $p->is_base,
                'derived' => $p->parent_id !== null,
                'adjustment_type' => $p->adjustment_type,
                // % entero, o monto por noche en unidades.
                'adjustment_value' => $p->adjustment_type === 'amount' ? $this->units($p->adjustment_value) : $p->adjustment_value,
                'cancellation' => $p->cancellation,
                'meal_plan' => $p->meal_plan,
                'enabled' => $p->enabled,
                'room_type_ids' => $p->roomTypes->pluck('id')->values(),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function planColumns(array $data): array
    {
        return [
            'name' => $data['name'],
            'code' => $data['code'],
            'cancellation' => $data['cancellation'],
            'meal_plan' => $data['meal_plan'],
            'enabled' => $data['enabled'] ?? true,
            'adjustment_type' => $data['adjustment_type'] ?? null,
            'adjustment_value' => match ($data['adjustment_type'] ?? null) {
                'amount' => $this->cents($data['adjustment_value'] ?? 0),
                'percent' => (int) round((float) ($data['adjustment_value'] ?? 0)),
                default => null,
            },
        ];
    }

    private function assertPlanData(int $accommodationId, array $data, ?RatePlan $plan = null): void
    {
        $errors = [];

        $codeTaken = RatePlan::where('accommodation_id', $accommodationId)
            ->where('code', $data['code'])
            ->when($plan, fn ($q) => $q->whereKeyNot($plan->id))
            ->exists();
        if ($codeTaken) {
            $errors['code'] = "Ya hay un plan con el código {$data['code']}.";
        }

        if (! ($plan?->is_base)) {
            if (empty($data['adjustment_type'])) {
                $errors['adjustment_type'] = 'Elegí cómo se calcula desde la tarifa base.';
            }
            $ids = $data['room_type_ids'] ?? [];
            $own = RoomType::where('accommodation_id', $accommodationId)->whereIn('id', $ids)->count();
            if ($ids === [] || $own !== count(array_unique($ids))) {
                $errors['room_type_ids'] = 'Elegí en qué tipos se vende.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function writablePlan(int $id): RatePlan
    {
        $plan = RatePlan::whereNotNull('accommodation_id')
            ->whereIn('accommodation_id', Accommodation::visibleTo($this->tenant())->select('accommodations.id'))
            ->find($id);
        abort_if($plan === null, 404);
        Gate::authorize('update', Accommodation::findOrFail($plan->accommodation_id));

        return $plan;
    }

    /** @return list<string> */
    private function dates(Carbon $from, Carbon $to): array
    {
        return collect(CarbonPeriod::create($from, $to->copy()->subDay()))
            ->map(fn (Carbon $d) => $d->toDateString())
            ->values()
            ->all();
    }

    private function currency(Accommodation $accommodation): string
    {
        $code = strtoupper(trim((string) $accommodation->currency_id));

        return strlen($code) === 3 ? $code : 'ARS';
    }

    private function units(?int $cents): ?float
    {
        return $cents === null ? null : $cents / 100;
    }

    private function cents(int|float|string|null $units): ?int
    {
        return $units === null || $units === '' ? null : (int) round(((float) $units) * 100);
    }
}
