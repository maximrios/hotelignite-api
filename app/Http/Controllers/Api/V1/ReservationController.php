<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use App\Repositories\Contracts\ReservationInterface;
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
        $reservations = $this->reservationInterface->all($request);
        return response()->json($reservations, 200);
    }
}