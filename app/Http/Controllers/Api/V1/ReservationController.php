<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\CancelReservationRequest;
use App\Http\Requests\CheckInReservationRequest;
use App\Http\Requests\CheckOutReservationRequest;
use App\Http\Requests\NoShowReservationRequest;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\UpdateReservationRequest;
use App\Http\Requests\UpdateTravelersRequest;
use App\Repositories\Contracts\ReservationInterface;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class ReservationController extends BaseController
{
    protected ReservationInterface $reservationInterface;

    public function __construct(ReservationInterface $reservationInterface)
    {
        $this->reservationInterface = $reservationInterface;
    }

    public function index(Request $request)
    {
        $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        $reservations = $this->reservationInterface->all($request);

        return response()->json($reservations, 200);
    }

    public function store(StoreReservationRequest $request)
    {
        return response()->json($this->reservationInterface->store($request), 201);
    }

    public function quote(Request $request)
    {
        return response()->json($this->reservationInterface->quote($request), 200);
    }

    public function guestLookup(Request $request)
    {
        return response()->json($this->reservationInterface->lookupGuests($request), 200);
    }

    public function update(UpdateReservationRequest $request, string $id)
    {
        return response()->json($this->reservationInterface->update($id, $request), 200);
    }

    public function cancel(CancelReservationRequest $request, string $id)
    {
        return response()->json($this->reservationInterface->cancel($id, $request), 200);
    }

    public function checkIn(CheckInReservationRequest $request, string $id)
    {
        return response()->json($this->reservationInterface->checkIn($id, $request), 200);
    }

    public function undoCheckIn(string $id)
    {
        return response()->json($this->reservationInterface->undoCheckIn($id), 200);
    }

    public function checkOut(CheckOutReservationRequest $request, string $id)
    {
        return response()->json($this->reservationInterface->checkOut($id, $request), 200);
    }

    public function undoCheckOut(string $id)
    {
        return response()->json($this->reservationInterface->undoCheckOut($id), 200);
    }

    public function noShow(NoShowReservationRequest $request, string $id)
    {
        return response()->json($this->reservationInterface->markNoShow($id, $request), 200);
    }

    public function undoNoShow(string $id)
    {
        return response()->json($this->reservationInterface->undoNoShow($id), 200);
    }

    public function show(string $id)
    {
        return response()->json($this->reservationInterface->show($id), 200);
    }

    public function updateTravelers(UpdateTravelersRequest $request, string $id)
    {
        return response()->json($this->reservationInterface->updateTravelers($id, $request), 200);
    }
}
