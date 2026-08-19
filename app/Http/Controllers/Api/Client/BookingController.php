<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Accommodation;
use App\Repositories\Contracts\BookingInterface;
use Illuminate\Routing\Controller as BaseController;

/**
 * Creación de pre-reservas por un consumidor B2B (un portal reserva en nombre
 * del turista). La ability `booking:create` se exige en la ruta
 * (`client.ability:booking:create`); acá se refuerza la tenencia: sólo se puede
 * reservar sobre accommodations que el client tiene relacionados.
 */
class BookingController extends BaseController
{
    protected BookingInterface $bookingInterface;

    public function __construct(BookingInterface $bookingInterface)
    {
        $this->bookingInterface = $bookingInterface;
    }

    public function store(StoreBookingRequest $request)
    {
        if ($request->filled('accommodation_id')) {
            $visible = Accommodation::visibleTo($request->user())
                ->whereKey($request->accommodation_id)
                ->exists();

            if (! $visible) {
                return response()->json([
                    'message' => 'No podés reservar sobre este alojamiento.',
                ], 403);
            }
        }

        return response()->json($this->bookingInterface->store($request), 201);
    }
}
