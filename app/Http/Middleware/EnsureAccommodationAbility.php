<?php

namespace App\Http\Middleware;

use App\Models\AccommodationApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que la API key del alojamiento tenga una ability concreta (ej.
 * `accommodation.ability:inquiry:create`). Corre después de `auth.accommodation`,
 * que deja la key en los attributes de la request. Gemelo de `EnsureClientAbility`.
 */
class EnsureAccommodationAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $key = $request->attributes->get('api_key');

        if (! $key instanceof AccommodationApiKey || ! $key->hasAbility($ability)) {
            return response()->json([
                'message' => "La credencial no tiene permiso para: {$ability}.",
            ], 403);
        }

        return $next($request);
    }
}
