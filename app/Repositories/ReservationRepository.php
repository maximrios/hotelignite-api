<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Resources\V1\ReservationResourceCollection;
use App\Models\Reservation;
use App\Repositories\Concerns\ScopesToAccommodation;
use App\Repositories\Contracts\ReservationInterface;
use Illuminate\Http\Request;

class ReservationRepository implements ReservationInterface
{
    use ScopesToAccommodation;

    public function all(Request $request)
    {
        $reservations = Reservation::visibleTo($this->tenant())
            ->with(['guest', 'room', 'channel'])
            ->when(
                $request->filled('accommodation_id'),
                fn ($query) => $query->where('accommodation_id', $request->input('accommodation_id'))
            )
            // El id ya no es secuencial (UUID); ordenamos por la fecha de estancia
            // efectiva, contemplando filas legacy (arrival) y nuevas (checkin_date).
            ->orderByRaw('COALESCE(checkin_date, arrival) DESC')
            ->paginate($request->integer('per_page', 25));

        return new ReservationResourceCollection($reservations);
    }
}
