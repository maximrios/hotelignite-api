<?php

namespace App\AI\Services;

use App\AI\Exceptions\AiToolException;
use App\AI\Support\AiContext;
use App\AI\Support\Mock\MockInventory;
use App\AI\Support\RoomTypeResolver;
use App\Models\Accommodation;
use App\Models\Rate;
use App\Models\RoomAvailability;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Throwable;

/**
 * Herramienta `check_availability`. A diferencia de
 * `AccommodationAvailabilityController::check` (que sigue igual para no romper
 * a los portales), acá:
 *
 * - el estado tiene tres valores: `available`, `unavailable` y `unknown` (sin
 *   inventario cargado). El controller trata "sin filas" como disponible, y un
 *   agente terminaría afirmando lugar que nadie confirmó;
 * - solo cuentan los room types donde entran los huéspedes (`max_occupancy`);
 * - devuelve precio "desde" por noche.
 *
 * Lo que falta se completa con `MockInventory` si está habilitado, y la
 * respuesta lo marca con `source: "mock"`.
 */
final class CheckAvailabilityService
{
    public function __construct(private readonly RoomTypeResolver $rooms) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(AiContext $ctx, int $accommodationId, string $checkin, string $checkout, int $adults, int $children = 0): array
    {
        [$from, $to] = self::parseStay($checkin, $checkout);

        $accommodation = Accommodation::visibleTo($ctx->actor)
            ->where('enabled', 1)
            ->with('roomTypes.descriptions')
            ->find($accommodationId);

        if ($accommodation === null) {
            throw new AiToolException("No encontré el alojamiento {$accommodationId}. Usá search_accommodations para obtener el id correcto.");
        }

        return [
            'accommodation' => [
                'id' => (int) $accommodation->id,
                'slug' => (string) $accommodation->slug,
                'name' => (string) $accommodation->name,
            ],
            ...$this->evaluate($accommodation, $from, $to, $adults + $children, $ctx->language),
        ];
    }

    /**
     * Valida y parsea las fechas de una estadía (`Y-m-d`).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function parseStay(string $checkin, string $checkout): array
    {
        try {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $checkin);
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $checkout);
        } catch (Throwable) {
            $from = $to = false;
        }

        if (! $from || ! $to) {
            throw new AiToolException('Las fechas tienen que tener formato YYYY-MM-DD.');
        }

        if ($from->lt(CarbonImmutable::today())) {
            throw new AiToolException('La fecha de llegada no puede ser anterior a hoy.');
        }

        if ($to->lte($from)) {
            throw new AiToolException('La fecha de salida tiene que ser posterior a la de llegada.');
        }

        $maxNights = (int) config('ai.max_stay_nights', 30);

        if ($from->diffInDays($to) > $maxNights) {
            throw new AiToolException("La estadía no puede superar las {$maxNights} noches.");
        }

        return [$from, $to];
    }

    /**
     * Disponibilidad y precio de un alojamiento ya cargado. La reutiliza
     * `SearchAvailabilityService` sobre cada candidato.
     *
     * @return array{status: 'available'|'unavailable'|'unknown', reason: ?string, nights: int, source: 'real'|'mock', rooms: list<array{name: string, max_occupancy: ?int}>, price_from: ?array{amount: int, currency: string, per: string, source: 'real'|'mock'}}
     */
    public function evaluate(Accommodation $accommodation, CarbonImmutable $from, CarbonImmutable $to, int $guests, string $language): array
    {
        $nights = (int) $from->diffInDays($to);
        $rooms = $this->rooms->forAccommodation($accommodation, $language);

        $fitting = array_values(array_filter(
            $rooms,
            fn (array $room) => $room['max_occupancy'] === null || $room['max_occupancy'] >= $guests,
        ));

        $usedMock = in_array('mock', array_column($rooms, 'source'), true);

        // Sin room types y sin mocks no hay con qué responder.
        if ($rooms === []) {
            return $this->result('unknown', 'no_data', $nights, $usedMock, $fitting);
        }

        // Ninguna habitación alcanza para el grupo.
        if ($fitting === []) {
            return $this->result('unavailable', 'capacity', $nights, $usedMock, $fitting);
        }

        [$status, $nightsMocked] = $this->nightlyStatus($accommodation, $fitting, $from, $to);
        $usedMock = $usedMock || $nightsMocked;

        // Si ninguna capacidad es conocida, "available" no está confirmado.
        $capacityKnown = array_filter($fitting, fn (array $room) => $room['max_occupancy'] !== null) !== [];

        if ($status === 'available' && ! $capacityKnown) {
            $status = 'unknown';
        }

        $price = $status === 'unavailable' ? null : $this->priceFrom($accommodation, $fitting, $from, $to, $guests);
        $reason = match ($status) {
            'unavailable' => 'no_availability',
            'unknown' => 'no_data',
            default => null,
        };

        return $this->result($status, $reason, $nights, $usedMock, $fitting, $price);
    }

    /**
     * @param  list<array{id: ?int, name: string, max_occupancy: ?int, source: string}>  $fitting
     * @param  ?string  $reason  `capacity` (ninguna habitación alcanza), `no_availability` (alguna noche sin lugar) o `no_data`
     * @param  ?array{amount: int, currency: string, per: string, source: 'real'|'mock'}  $price
     * @return array{status: 'available'|'unavailable'|'unknown', reason: ?string, nights: int, source: 'real'|'mock', rooms: list<array{name: string, max_occupancy: ?int}>, price_from: ?array{amount: int, currency: string, per: string, source: 'real'|'mock'}}
     */
    private function result(string $status, ?string $reason, int $nights, bool $usedMock, array $fitting, ?array $price = null): array
    {
        return [
            'status' => $status,
            'reason' => $reason,
            'nights' => $nights,
            'source' => $usedMock || ($price['source'] ?? null) === 'mock' ? 'mock' : 'real',
            'rooms' => array_map(fn (array $room) => [
                'name' => $room['name'],
                'max_occupancy' => $room['max_occupancy'],
            ], $fitting),
            'price_from' => $price,
        ];
    }

    /**
     * Recorre las noches: con filas en `room_availabilities` decide el dato real;
     * sin filas, el mock (si está habilitado) o `unknown`.
     *
     * @param  list<array{id: ?int, name: string, max_occupancy: ?int, source: string}>  $fitting
     * @return array{0: 'available'|'unavailable'|'unknown', 1: bool}
     */
    private function nightlyStatus(Accommodation $accommodation, array $fitting, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $realIds = array_values(array_filter(array_column($fitting, 'id')));
        $mockEnabled = MockInventory::enabled();

        $rows = $realIds === [] ? collect() : RoomAvailability::query()
            ->whereIn('room_type_id', $realIds)
            ->whereBetween('date', [$from->toDateString(), $to->subDay()->toDateString()])
            ->get(['room_type_id', 'date', 'available', 'closed'])
            ->groupBy(fn (RoomAvailability $row) => CarbonImmutable::parse($row->date)->toDateString());

        $status = 'available';
        $usedMock = false;

        foreach (CarbonPeriod::create($from, $to->subDay()) as $date) {
            $night = CarbonImmutable::instance($date);
            $nightRows = $rows->get($night->toDateString());

            if ($nightRows !== null && $nightRows->isNotEmpty()) {
                $open = $nightRows->contains(fn (RoomAvailability $row) => (int) $row->available > 0 && ! $row->closed);
            } elseif ($mockEnabled) {
                $open = MockInventory::isNightOpen((int) $accommodation->id, $night);
                $usedMock = true;
            } else {
                $status = 'unknown';

                continue;
            }

            if (! $open) {
                return ['unavailable', $usedMock];
            }
        }

        return [$status, $usedMock];
    }

    /**
     * Precio "desde" por noche: la tarifa más baja cargada para las habitaciones
     * donde entra el grupo; sin tarifas, el mock (M2); sin mock, null.
     *
     * @param  list<array{id: ?int, name: string, max_occupancy: ?int, source: string}>  $fitting
     * @return ?array{amount: int, currency: string, per: string, source: 'real'|'mock'}
     */
    private function priceFrom(Accommodation $accommodation, array $fitting, CarbonImmutable $from, CarbonImmutable $to, int $guests): ?array
    {
        $realIds = array_values(array_filter(array_column($fitting, 'id')));

        if ($realIds !== []) {
            $rate = Rate::query()
                ->whereHas('ratePlan', fn ($q) => $q->whereIn('room_type_id', $realIds)->where('enabled', true))
                ->whereBetween('date', [$from->toDateString(), $to->subDay()->toDateString()])
                ->orderBy('price')
                ->first(['price', 'currency']);

            // `rates.price` está en centavos.
            if ($rate !== null) {
                return ['amount' => (int) round($rate->price_decimal), 'currency' => (string) ($rate->currency ?: 'ARS'), 'per' => 'night', 'source' => 'real'];
            }
        }

        if (! MockInventory::enabled()) {
            return null;
        }

        $smallest = min(array_map(fn (array $room) => $room['max_occupancy'] ?? $guests, $fitting));

        return [
            'amount' => MockInventory::nightlyPrice($accommodation, max($smallest, $guests), $from),
            'currency' => 'ARS',
            'per' => 'night',
            'source' => 'mock',
        ];
    }
}
