<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\BookingRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Repositories\Contracts\BookingInterface;
use Illuminate\Routing\Controller as BaseController;

class BookingController extends BaseController
{

    protected BookingInterface $bookingInterface;

    public function __construct(BookingInterface $bookingInterface)
    {
        $this->bookingInterface = $bookingInterface;
    }

    public function index(Request $request)
    {
        $bookings = $this->bookingInterface->all($request);
        return response()->json($bookings, 200);
    }
    
    public function store(StoreBookingRequest $request)
    {
        return response()->json( $this->bookingInterface->store($request), 200 );   
    }

    public function update(Request $request)
    {
        return response()->json( $this->bookingInterface->update($request), 200 );   
    }

    public function show(Request $request)
    {
        return response()->json( $this->bookingInterface->find($request->token), 200);
        
    }
}