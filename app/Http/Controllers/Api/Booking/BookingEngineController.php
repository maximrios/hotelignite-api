<?php

namespace App\Http\Controllers\Api\Booking;

use App\Http\Requests\Booking\BookingStayRequest;
use App\Http\Requests\Booking\ConfirmBookingRequest;
use App\Models\Accommodation;
use App\Repositories\Contracts\BookingEngineInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class BookingEngineController extends BaseController
{
    public function __construct(private BookingEngineInterface $engine) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->engine->hotel($this->accommodation($request))]);
    }

    public function search(BookingStayRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->engine->search($this->accommodation($request), $request->validated())]);
    }

    public function hold(BookingStayRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->engine->hold(
            $this->accommodation($request),
            $request->validated(),
            (string) $request->header('X-Engine-Client-Ip', (string) $request->ip()),
        )], 201);
    }

    public function showHold(Request $request, string $slug, string $token): JsonResponse
    {
        return response()->json(['data' => $this->engine->showHold($this->accommodation($request), $token)]);
    }

    /** El huésped cambia lo que eligió: se libera lo apartado sin esperar los 15 minutos. */
    public function releaseHold(Request $request, string $slug, string $token): JsonResponse
    {
        $this->engine->releaseHold($this->accommodation($request), $token);

        return response()->json(null, 204);
    }

    public function confirm(ConfirmBookingRequest $request, string $slug, string $token): JsonResponse
    {
        return response()->json(['data' => $this->engine->confirm($this->accommodation($request), $token, $request->validated())]);
    }

    private function accommodation(Request $request): Accommodation
    {
        return $request->attributes->get('booking_accommodation');
    }
}
