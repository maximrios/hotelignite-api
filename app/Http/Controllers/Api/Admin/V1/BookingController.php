<?php

namespace App\Http\Controllers\Api\Admin\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\BookingRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Repositories\Contracts\BookingInterface;
use Illuminate\Routing\Controller as BaseController;

class BookingController extends BaseController
{

    public function index(Request $request)
    {
        $bookings = Booking::visibleTo($request->user())->get();
        return response()->json($bookings, 200);
    }
}