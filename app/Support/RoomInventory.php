<?php

namespace App\Support;

use App\Models\Room;
use App\Models\RoomAvailability;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El cupo de un tipo de habitación, igual para el motor de reservas y el agente.
 *
 * El inventario son las **habitaciones físicas** (`rooms`) libres para toda la
 * estadía: sin reserva activa ni retención vigente del motor, y que no estén
 * fuera de servicio. `room_availability` **no** es el inventario: es la grilla
 * donde el hotel **cierra** fechas (o deja el cupo en 0). Sin filas ahí, está
 * abierto.
 */
final class RoomInventory
{
    /** @return Collection<int, Room> */
    public static function freeRooms(int $roomTypeId, CarbonInterface $checkin, CarbonInterface $checkout): Collection
    {
        $from = $checkin->toDateString();
        $to = $checkout->toDateString();

        return Room::where('room_type_id', $roomTypeId)
            ->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('housekeeping_status')->orWhere('housekeeping_status', '<>', 'ooo'))
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['maintenance', 'blocked']))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('reservations')
                ->whereColumn('reservations.room_id', 'rooms.id')
                ->whereNull('cancelled_at')->whereNull('no_show_at')->whereNull('deleted_at')
                ->where('checkin_date', '<', $to)->where('checkout_date', '>', $from))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('booking_hold_rooms')
                ->join('booking_holds', 'booking_holds.id', '=', 'booking_hold_rooms.booking_hold_id')
                ->whereColumn('booking_hold_rooms.room_id', 'rooms.id')
                ->whereNull('submitted_at')->where('expires_at', '>', now())
                ->where('checkin_date', '<', $to)->where('checkout_date', '>', $from))
            ->orderBy('id')
            ->get();
    }

    /** Cierre manual del hotel en la grilla de disponibilidad, en alguna noche de la estadía. */
    public static function isClosed(int $roomTypeId, CarbonInterface $checkin, CarbonInterface $checkout): bool
    {
        return RoomAvailability::where('room_type_id', $roomTypeId)
            ->where('date', '>=', $checkin->toDateString())
            ->where('date', '<', $checkout->toDateString())
            ->where(fn ($q) => $q->where('closed', true)->orWhere('available', '<=', 0))
            ->exists();
    }

    /** Si el tipo tiene habitaciones físicas cargadas (o sea, si hay inventario real). */
    public static function hasRooms(int $roomTypeId): bool
    {
        return Room::where('room_type_id', $roomTypeId)->whereNull('archived_at')->exists();
    }
}
