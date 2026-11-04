<?php

namespace App\Http\Middleware;

use App\Models\Accommodation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Credencial de servicio del motor (`engine/`): la usa su servidor, nunca el
 * navegador. Resuelve el alojamiento del slug y exige motor activado y plan.
 */
class AuthenticateBookingEngine
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('api.booking_engine_key');
        $given = (string) $request->bearerToken();

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Credencial del motor inválida.'], 401);
        }

        $accommodation = Accommodation::with('plan')
            ->where('slug', (string) $request->route('slug'))
            ->where('enabled', 1)
            ->first();

        if ($accommodation === null
            || $accommodation->booking_mode !== Accommodation::BOOKING_REQUEST
            || ! $accommodation->allowsOnlineBookings()) {
            return response()->json(['message' => 'Este alojamiento no recibe reservas online.'], 404);
        }

        $request->attributes->set('booking_accommodation', $accommodation);

        return $next($request);
    }
}
