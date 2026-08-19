<?php

namespace App\Http\Middleware;

use App\Models\ClientApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que la API key del client tenga una ability concreta para acceder a la
 * ruta (ej. `client.ability:booking:create`). Corre después de `auth.client`,
 * que deja la key en los attributes de la request.
 */
class EnsureClientAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $key = $request->attributes->get('api_key');

        if (! $key instanceof ClientApiKey || ! $key->hasAbility($ability)) {
            return response()->json([
                'message' => "La credencial no tiene permiso para: {$ability}.",
            ], 403);
        }

        return $next($request);
    }
}
