<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Resources\V1\BookingResource;
use App\Http\Resources\V1\BookingResourceCollection;
use App\Http\Resources\V1\ReservationResource;
use App\Models\Booking;
use App\Repositories\Concerns\ScopesToAccommodation;
use App\Repositories\Contracts\BookingInterface;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class BookingRepository implements BookingInterface
{
    use ScopesToAccommodation;

    public function all(Request $request)
    {
        $bookings = Booking::visibleTo($this->tenant())->get();

        return new BookingResourceCollection($bookings);
    }

    public function find($id)
    {
        $booking = Booking::visibleTo($this->tenant())->find($id);

        abort_if($booking === null, 404);

        // Sí, un Booking envuelto en ReservationResource. Es el contrato que ya
        // consume el front; corregirlo es harina de otro costal.
        return new ReservationResource($booking);
    }

    public function store($request)
    {
        // Crear una pre-reserva es una escritura legítima de un tercero sobre el
        // alojamiento (un portal B2B reservando), así que alcanza con verlo.
        // El campo es opcional (hay pre-reservas de tours), y en ese caso el
        // chequeo del token de abajo es el que rechaza el pedido.
        $accommodationId = $request->accommodation_id !== null
            ? $this->visibleAccommodation($request->accommodation_id)->id
            : null;

        $tokenKey = "availability_token:{$request->availability_token}";
        $tokenData = Cache::get($tokenKey);

        if (! $tokenData) {
            abort(422, 'El token de disponibilidad no es válido o expiró. Verificá la disponibilidad nuevamente.');
        }

        if ((string) $tokenData['accommodation_id'] !== (string) $accommodationId) {
            abort(422, 'El token de disponibilidad no corresponde a este alojamiento.');
        }

        // One-use: consume the token immediately
        Cache::forget($tokenKey);

        $checkin = Carbon::parse($request->checkin)->format('Y-m-d');
        $checkout = $request->checkout ? Carbon::parse($request->checkout)->format('Y-m-d') : null;

        $booking = new Booking();
        $booking->adults = $request->adults;
        $booking->childrens = $request->childrens;
        $booking->checkin = $checkin;
        $booking->checkout = $checkout;
        $booking->accommodation_id = $accommodationId;
        $booking->room_id = $request->room_id;
        $booking->tour_id = $request->tour_id;
        $booking->save();

        return new BookingResource($booking);
    }

    public function update($request)
    {
        $booking = Booking::visibleTo($this->tenant())->find($request->id);

        abort_if($booking === null, 404);
        Gate::authorize('update', $booking);

        $booking->name = $request->name;
        $booking->lastname = $request->lastname;
        $booking->email = $request->email;
        $booking->phone = $request->phone;
        $booking->save();

        return new BookingResource($booking);
    }
}
