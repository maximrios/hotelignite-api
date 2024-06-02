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
use App\Http\Resources\V1\BookingResourceCollection;
use App\Http\Resources\V1\ReservationResourceCollection;
use App\Models\Reservation;
use App\Repositories\Contracts\ReservationInterface;

class ReservationRepository implements ReservationInterface
{
    public function all(Request $request)
    {
        $reservations = Reservation::all();
        return new ReservationResourceCollection($reservations);
    }
}
