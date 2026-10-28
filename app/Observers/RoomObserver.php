<?php

namespace App\Observers;

use App\Models\HousekeepingEvent;
use App\Models\Room;
use Illuminate\Support\Facades\Auth;

/**
 * Housekeeping se entera de los cambios de estado que hacen recepción (check-in,
 * check-out, deshacer) y el inventario sin que esos módulos lo sepan: todos
 * terminan en `$room->save()`.
 */
class RoomObserver
{
    public function saving(Room $room): void
    {
        if (! $room->exists) {
            return;
        }

        if ($room->isDirty('housekeeping_status')) {
            $to = $room->housekeeping_status;

            // Ensuciar invalida la inspección anterior.
            if (in_array($to, ['vd', 'od'], true)) {
                $room->inspected_at = null;
            }

            if ($room->getOriginal('housekeeping_status') === 'ooo' && $to !== 'ooo') {
                $room->out_of_service_until = null;
            }

            // El "no molestar" es de la estadía de hoy: vale sólo con huésped alojado.
            if (! in_array($to, ['oc', 'od'], true)) {
                $room->dnd_date = null;
            }
        }
    }

    public function updated(Room $room): void
    {
        if (HousekeepingEvent::isSilenced()) {
            return;
        }

        $statusChanged = $room->wasChanged('housekeeping_status');

        if (! $statusChanged && ! $room->wasChanged('maintenance_note')) {
            return;
        }

        HousekeepingEvent::create([
            'accommodation_id' => $room->accommodation_id,
            'room_id' => $room->id,
            'type' => $statusChanged ? 'status_changed' : 'note_changed',
            'from_status' => $statusChanged ? $room->getOriginal('housekeeping_status') : null,
            'to_status' => $statusChanged ? $room->housekeeping_status : null,
            'user_id' => Auth::id(),
            'source' => 'system',
            'note' => in_array($room->housekeeping_status, ['ooo', 'oos'], true) ? $room->maintenance_note : null,
        ]);
    }
}
