<?php

declare(strict_types=1);

namespace App\Repositories;

use Carbon\Carbon;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Http\Requests\BookingRequest;
use App\Http\Resources\V1\TourResource;
use App\Http\Resources\V1\BookingResource;
use App\Http\Resources\V1\BookingResourceCollection;
use App\Http\Resources\V1\ReservationResource;
use App\Repositories\Contracts\BookingInterface;
use App\Http\Resources\V1\TourResourceCollection;

class BookingRepository implements BookingInterface
{
    public function all(Request $request)
    {
        $bookings = Booking::all();
        return new BookingResourceCollection($bookings);
    }
    public function find($id)
    {
        $reservation = Booking::find($id);
        return new ReservationResource($reservation);
    }
    public function store($request)
    {
        $tokenKey  = "availability_token:{$request->availability_token}";
        $tokenData = Cache::get($tokenKey);

        if (!$tokenData) {
            abort(422, 'El token de disponibilidad no es válido o expiró. Verificá la disponibilidad nuevamente.');
        }

        if ((string) $tokenData['accommodation_id'] !== (string) $request->accommodation_id) {
            abort(422, 'El token de disponibilidad no corresponde a este alojamiento.');
        }

        // One-use: consume the token immediately
        Cache::forget($tokenKey);

        $checkin  = Carbon::parse($request->checkin)->format('Y-m-d');
        $checkout = $request->checkout ? Carbon::parse($request->checkout)->format('Y-m-d') : null;

        $booking = new Booking();
        $booking->adults           = $request->adults;
        $booking->childrens        = $request->childrens;
        $booking->checkin          = $checkin;
        $booking->checkout         = $checkout;
        $booking->accommodation_id = $request->accommodation_id;
        $booking->room_id          = $request->room_id;
        $booking->tour_id          = $request->tour_id;
        $booking->save();

        return new BookingResource($booking);
    }
    public function update($request)
    {

        $booking = Booking::find($request->id);
        $booking->name = $request->name;
        $booking->lastname = $request->lastname;
        $booking->email = $request->email;
        $booking->phone = $request->phone;
        $booking->save();
        return new BookingResource($booking);
    }
}
