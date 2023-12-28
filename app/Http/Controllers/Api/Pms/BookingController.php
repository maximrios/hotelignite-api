<?php

namespace App\Http\Controllers\Api\Pms;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\BookingRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Repositories\Contracts\BookingInterface;
use Illuminate\Routing\Controller as BaseController;

class BookingController extends BaseController
{

    public function index()
    {
        $reservations = Booking::all();
        return response()->json($reservations, 200);
    }
}