<?php

declare(strict_types=1);

namespace App\Repositories;

use Carbon\Carbon;
use App\Models\Booking;
use Illuminate\Http\Request;
use App\Http\Requests\BookingRequest;
use App\Http\Resources\V1\TourResource;
use App\Http\Resources\V1\BookingResource;
use App\Http\Resources\V1\ReservationResource;
use App\Repositories\Contracts\BookingInterface;
use App\Http\Resources\V1\TourResourceCollection;

class BookingRepository implements BookingInterface
{
    public function all(Request $request)
    {
        $tours = Booking::all();
        return new TourResourceCollection($tours);
    }
    public function find($id)
    {
        $reservation = Booking::find($id);
        return new ReservationResource($reservation);
    }
    public function store($request)
    {
        $checkin = Carbon::createFromFormat('d/m/Y', $request->checkin)->format('Y-m-d');
        if($request->checkout) {
            $checkout = Carbon::createFromFormat('d/m/Y', $request->checkout)->format('Y-m-d');
        }
        else {
            $checkout = null;
        }

        $booking = new Booking();
        $booking->adults = $request->adults;
        $booking->childrens = $request->childrens;
        $booking->checkin = $checkin;
        $booking->checkout = $checkout;
        $booking->accommodation_id = $request->accommodation_id;
        $booking->room_id = $request->room_id;
        $booking->tour_id = $request->tour_id;
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
