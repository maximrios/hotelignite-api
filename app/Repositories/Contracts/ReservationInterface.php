<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Http\Requests\CancelReservationRequest;
use App\Http\Requests\CheckInReservationRequest;
use App\Http\Requests\CheckOutReservationRequest;
use App\Http\Requests\NoShowReservationRequest;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\UpdateReservationRequest;
use App\Http\Requests\UpdateTravelersRequest;
use Illuminate\Http\Request;

interface ReservationInterface
{
    public function all(Request $request);

    public function store(StoreReservationRequest $request);

    public function quote(Request $request): array;

    public function lookupGuests(Request $request): array;

    public function update(string $id, UpdateReservationRequest $request);

    public function cancel(string $id, CancelReservationRequest $request);

    public function checkIn(string $id, CheckInReservationRequest $request);

    public function undoCheckIn(string $id);

    public function checkOut(string $id, CheckOutReservationRequest $request);

    public function undoCheckOut(string $id);

    public function markNoShow(string $id, NoShowReservationRequest $request);

    public function undoNoShow(string $id);

    public function show(string $id);

    public function updateTravelers(string $id, UpdateTravelersRequest $request);
}
