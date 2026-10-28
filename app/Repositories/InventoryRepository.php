<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Requests\Inventory\StoreRoomsRequest;
use App\Http\Requests\Inventory\UpdateInventoryRoomRequest;
use App\Http\Requests\Inventory\UpsertRoomTypeRequest;
use App\Models\Accommodation;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Repositories\Concerns\ScopesToAccommodation;
use App\Repositories\Contracts\InventoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Inventario del PMS: tipos de habitación (lo que se vende y se publica en
 * canales) y habitaciones físicas (las filas del rack). Reglas acordadas con
 * hotelería:
 *
 * - No hay habitaciones "virtuales": cada física pertenece a un solo tipo y las
 *   variantes (uso single, triple con cama extra) se resuelven por ocupación y
 *   tarifa del tipo.
 * - La cantidad del tipo se deriva de sus habitaciones activas; no se tipea.
 * - Una habitación que tuvo reservas no se borra: se da de baja (`archived_at`).
 *   Con reservas vigentes o futuras, ni eso: primero hay que reasignarlas.
 * - Cambiar el tipo de una habitación con reservas futuras pide confirmación y
 *   no toca el tipo vendido ni el precio de esas reservas.
 */
class InventoryRepository implements InventoryInterface
{
    use ScopesToAccommodation;

    public function show(Request $request): array
    {
        $accommodation = $this->visibleAccommodation($request->integer('accommodation_id'));
        $today = Reservation::today()->toDateString();

        $types = RoomType::where('accommodation_id', $accommodation->id)
            ->with(['descriptions', 'beds'])
            ->orderBy('id')
            ->get();

        $rooms = Room::where('accommodation_id', $accommodation->id)->get();

        // Reservas vigentes o futuras por habitación: bloquean la baja y avisan
        // al cambiar de tipo o poner fuera de servicio.
        $upcoming = Reservation::where('accommodation_id', $accommodation->id)
            ->whereNotNull('room_id')
            ->whereNull('cancelled_at')
            ->whereNull('no_show_at')
            ->whereNull('checked_out_at')
            ->where('checkout_date', '>', $today)
            ->selectRaw('room_id, COUNT(*) AS total, MIN(checkin_date) AS first_checkin')
            ->groupBy('room_id')
            ->get()
            ->keyBy('room_id');

        return [
            'room_types' => $types->map(fn (RoomType $type) => $this->typePayload($type, $rooms))->values(),
            'rooms' => $rooms
                ->sortBy(fn (Room $room) => $room->number, SORT_NATURAL)
                ->map(fn (Room $room) => $this->roomPayload($room, $upcoming->get($room->id)))
                ->values(),
        ];
    }

    public function storeType(UpsertRoomTypeRequest $request): array
    {
        $accommodation = $this->writableAccommodation($request->integer('accommodation_id'));
        $data = $request->validated();
        $this->assertUniqueCode($accommodation->id, $data['code']);

        $type = DB::transaction(function () use ($accommodation, $data) {
            $type = RoomType::create([
                'accommodation_id' => $accommodation->id,
                'status' => $data['status'] ?? 'active',
                'quantity' => 0,
                ...$this->typeColumns($data),
            ]);
            $this->syncTypeTexts($type, $data);

            return $type;
        });

        return $this->typePayload($type->load(['descriptions', 'beds']), collect());
    }

    public function updateType(int $id, UpsertRoomTypeRequest $request): array
    {
        $type = $this->writableType($id);
        $data = $request->validated();
        $this->assertUniqueCode((int) $type->accommodation_id, $data['code'], $type->id);

        DB::transaction(function () use ($type, $data) {
            $type->update([
                'status' => $data['status'] ?? $type->status ?? 'active',
                ...$this->typeColumns($data),
            ]);
            $this->syncTypeTexts($type, $data);
        });

        $rooms = Room::where('room_type_id', $type->id)->get();

        return $this->typePayload($type->refresh()->load(['descriptions', 'beds']), $rooms);
    }

    /**
     * Sólo se borra un tipo sin habitaciones ni reservas; si no, se desactiva
     * (deja de venderse y conserva el historial).
     */
    public function destroyType(int $id): void
    {
        $type = $this->writableType($id);

        $rooms = Room::where('room_type_id', $type->id)->count();
        abort_if($rooms > 0, 409, "El tipo tiene {$rooms} ".($rooms === 1 ? 'habitación' : 'habitaciones').'. Pasalas a otro tipo o desactivalo.');

        $reservations = Reservation::where('room_type_id', $type->id)->count();
        abort_if($reservations > 0, 409, 'El tipo tiene reservas: desactivalo en vez de eliminarlo.');

        $type->delete();
    }

    /**
     * Alta masiva: una lista de números (el PMS arma la serie "101 a 110"). Los
     * números son únicos entre las habitaciones activas del alojamiento.
     *
     * @return list<array<string, mixed>>
     */
    public function storeRooms(StoreRoomsRequest $request): array
    {
        $data = $request->validated();
        $type = $this->writableType((int) $data['room_type_id']);
        $numbers = array_values(array_unique(array_map('trim', $data['numbers'])));

        $taken = $this->takenNumbers((int) $type->accommodation_id, $numbers);
        if ($taken !== []) {
            throw ValidationException::withMessages([
                'numbers' => (count($taken) === 1 ? 'Ya existe la habitación ' : 'Ya existen las habitaciones ').implode(', ', $taken).'.',
            ]);
        }

        $this->assertRoomLimit((int) $type->accommodation_id, count($numbers));

        $created = DB::transaction(function () use ($type, $numbers, $data) {
            $rooms = array_map(fn (string $number) => Room::create([
                'accommodation_id' => $type->accommodation_id,
                'room_type_id' => $type->id,
                'number' => $number,
                'floor' => $data['floor'] ?? null,
                'status' => 'available',
                'housekeeping_status' => 'vc',
                'features' => $data['features'] ?? [],
            ]), $numbers);
            $this->syncQuantity($type->id);

            return $rooms;
        });

        return array_map(fn (Room $room) => $this->roomPayload($room, null), $created);
    }

    public function updateRoom(int $id, UpdateInventoryRoomRequest $request): array
    {
        $room = $this->writableRoom($id);
        $data = $request->validated();
        $upcoming = $this->upcomingFor($room);

        abort_if($room->archived_at !== null, 422, 'La habitación está dada de baja: reactivala para editarla.');

        if (isset($data['number']) && $data['number'] !== $room->number
            && $this->takenNumbers((int) $room->accommodation_id, [$data['number']], $room->id) !== []) {
            throw ValidationException::withMessages(['number' => "Ya existe la habitación {$data['number']}."]);
        }

        $newTypeId = isset($data['room_type_id']) ? (int) $data['room_type_id'] : (int) $room->room_type_id;
        if ($newTypeId !== (int) $room->room_type_id) {
            $this->writableType($newTypeId, (int) $room->accommodation_id);

            if ($upcoming->count() > 0 && ! ($data['confirm_type_change'] ?? false)) {
                throw ValidationException::withMessages([
                    'room_type_id' => "Tiene {$upcoming->count()} ".($upcoming->count() === 1 ? 'reserva' : 'reservas')
                        .' por delante: van a quedar en una habitación de otro tipo (sin cambiar lo vendido). Confirmá el cambio.',
                ]);
            }
        }

        if (array_key_exists('out_of_service', $data)) {
            $inHouse = $upcoming->first(fn (Reservation $r) => $r->checked_in_at !== null);
            abort_if(
                $data['out_of_service'] && $room->housekeeping_status !== 'ooo' && $inHouse !== null,
                409,
                'Tiene un huésped alojado: hacé el check-out o mudalo antes de ponerla fuera de servicio.'
            );
        }

        DB::transaction(function () use ($room, $data, $newTypeId) {
            $oldTypeId = (int) $room->room_type_id;

            $room->fill(array_intersect_key($data, array_flip(['number', 'floor', 'notes', 'features'])));
            $room->room_type_id = $newTypeId;

            if (array_key_exists('out_of_service', $data)) {
                if ($data['out_of_service']) {
                    $room->housekeeping_status = 'ooo';
                    $room->maintenance_note = $data['maintenance_note'] ?? null;
                } elseif ($room->housekeeping_status === 'ooo') {
                    // Sale de mantenimiento: hay que revisarla antes de venderla.
                    $room->housekeeping_status = 'vd';
                    $room->maintenance_note = null;
                }
            }
            $room->save();

            if ($oldTypeId !== $newTypeId) {
                $this->syncQuantity($oldTypeId);
                $this->syncQuantity($newTypeId);
            }
        });

        return $this->roomPayload($room->refresh(), $this->upcomingSummary($room));
    }

    /**
     * Borrar: sin historial se elimina; con historial se da de baja. Con
     * reservas vigentes o futuras no se puede ninguna de las dos.
     *
     * @return array{deleted: bool, room: array<string, mixed>|null}
     */
    public function destroyRoom(int $id): array
    {
        $room = $this->writableRoom($id);
        $upcoming = $this->upcomingFor($room);

        if ($upcoming->isNotEmpty()) {
            $first = $upcoming->sortBy('checkin_date')->first();
            abort(409, "Tiene {$upcoming->count()} ".($upcoming->count() === 1 ? 'reserva' : 'reservas')
                ." desde el {$first->checkin_date->format('d/m')}: reasignalas primero.");
        }

        $hasHistory = Reservation::where('room_id', $room->id)->exists();

        DB::transaction(function () use ($room, $hasHistory) {
            if ($hasHistory) {
                $room->archived_at = now();
                $room->save();
            } else {
                $room->delete();
            }
            $this->syncQuantity((int) $room->room_type_id);
        });

        return ['deleted' => ! $hasHistory, 'room' => $hasHistory ? $this->roomPayload($room, null) : null];
    }

    public function restoreRoom(int $id): array
    {
        $room = $this->writableRoom($id);

        abort_if($room->archived_at === null, 422, 'La habitación ya está activa.');
        $this->assertRoomLimit((int) $room->accommodation_id, 1);

        if ($this->takenNumbers((int) $room->accommodation_id, [$room->number], $room->id) !== []) {
            abort(409, "Ya hay otra habitación {$room->number} activa: cambiale el número antes de reactivar ésta.");
        }

        DB::transaction(function () use ($room) {
            $room->archived_at = null;
            $room->save();
            $this->syncQuantity((int) $room->room_type_id);
        });

        return $this->roomPayload($room->refresh(), null);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    /** @param  Collection<int, Room>  $rooms */
    private function typePayload(RoomType $type, Collection $rooms): array
    {
        $description = $type->descriptions->firstWhere('language_id', 'es') ?? $type->descriptions->first();
        $own = $rooms->where('room_type_id', $type->id);

        return [
            'id' => $type->id,
            'name' => $description?->name,
            'code' => $type->code,
            'description' => $description?->description,
            'standard_occupancy' => $type->standard_occupancy,
            'max_occupancy' => $type->max_occupancy,
            'max_adults' => $type->max_adults,
            'max_children' => $type->max_children,
            'size' => $type->size !== null ? (float) $type->size : null,
            'status' => $type->status ?? 'active',
            'beds' => $type->beds->map(fn ($bed) => ['type' => $bed->type, 'quantity' => $bed->quantity])->values(),
            'rooms_count' => $own->whereNull('archived_at')->count(),
        ];
    }

    private function roomPayload(Room $room, ?object $upcoming): array
    {
        return [
            'id' => $room->id,
            'number' => $room->number,
            'floor' => $room->floor,
            'room_type_id' => (int) $room->room_type_id,
            'housekeeping_status' => $room->housekeeping_status,
            'out_of_service' => $room->housekeeping_status === 'ooo'
                || in_array($room->status, ['maintenance', 'blocked'], true),
            'maintenance_note' => $room->maintenance_note,
            'features' => $room->features ?? [],
            'notes' => $room->notes,
            'archived' => $room->archived_at !== null,
            'upcoming_reservations' => (int) ($upcoming->total ?? 0),
            'next_checkin' => isset($upcoming->first_checkin) ? substr((string) $upcoming->first_checkin, 0, 10) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function typeColumns(array $data): array
    {
        return [
            'code' => $data['code'],
            'standard_occupancy' => $data['standard_occupancy'],
            'max_occupancy' => $data['max_occupancy'],
            'max_adults' => $data['max_adults'],
            'max_children' => $data['max_children'] ?? null,
            'size' => $data['size'] ?? null,
        ];
    }

    /** Nombre y descripción (en español) y camas, que viven en tablas propias. */
    private function syncTypeTexts(RoomType $type, array $data): void
    {
        $type->descriptions()->updateOrCreate(
            ['language_id' => 'es'],
            ['name' => $data['name'], 'description' => $data['description'] ?? null]
        );

        $type->beds()->delete();
        foreach ($data['beds'] as $bed) {
            $type->beds()->create(['type' => $bed['type'], 'quantity' => $bed['quantity']]);
        }
    }

    private function assertUniqueCode(int $accommodationId, string $code, ?int $exceptId = null): void
    {
        $taken = RoomType::where('accommodation_id', $accommodationId)
            ->whereRaw('UPPER(code) = ?', [strtoupper($code)])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['code' => "Ya hay un tipo con el código {$code}."]);
        }
    }

    /**
     * @param  list<string>  $numbers
     * @return list<string>
     */
    private function takenNumbers(int $accommodationId, array $numbers, ?int $exceptId = null): array
    {
        return Room::where('accommodation_id', $accommodationId)
            ->active()
            ->whereIn('number', $numbers)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->pluck('number')
            ->sort(SORT_NATURAL)
            ->values()
            ->all();
    }

    /** Tope de habitaciones activas del plan (el PMS ofrece cambiar de plan). */
    private function assertRoomLimit(int $accommodationId, int $adding): void
    {
        $limit = Accommodation::with('plan.features')->findOrFail($accommodationId)->roomLimit();
        if ($limit === null) {
            return;
        }

        $active = Room::where('accommodation_id', $accommodationId)->active()->count();
        if ($active + $adding > $limit) {
            throw ValidationException::withMessages([
                'numbers' => "Tu plan permite hasta {$limit} habitaciones y ya tenés {$active}. Podés cambiar de plan desde Facturación.",
            ]);
        }
    }

    /** Cantidad del tipo = habitaciones activas (la leen disponibilidad y canales). */
    private function syncQuantity(int $roomTypeId): void
    {
        RoomType::whereKey($roomTypeId)->update([
            'quantity' => Room::where('room_type_id', $roomTypeId)->active()->count(),
        ]);
    }

    /** @return Collection<int, Reservation> */
    private function upcomingFor(Room $room): Collection
    {
        return Reservation::where('room_id', $room->id)
            ->whereNull('cancelled_at')
            ->whereNull('no_show_at')
            ->whereNull('checked_out_at')
            ->where('checkout_date', '>', Reservation::today()->toDateString())
            ->get();
    }

    private function upcomingSummary(Room $room): object
    {
        $upcoming = $this->upcomingFor($room);

        return (object) [
            'total' => $upcoming->count(),
            'first_checkin' => $upcoming->min('checkin_date')?->toDateString(),
        ];
    }

    private function writableType(int $id, ?int $accommodationId = null): RoomType
    {
        $type = RoomType::visibleTo($this->tenant())->find($id);

        abort_if($type === null, 404);
        abort_if(
            $accommodationId !== null && (int) $type->accommodation_id !== $accommodationId,
            422,
            'El tipo de habitación no pertenece a este alojamiento.'
        );
        Gate::authorize('update', Accommodation::findOrFail($type->accommodation_id));

        return $type;
    }

    private function writableRoom(int $id): Room
    {
        $room = Room::visibleTo($this->tenant())->find($id);

        abort_if($room === null, 404);
        Gate::authorize('update', Accommodation::findOrFail($room->accommodation_id));

        return $room;
    }
}
