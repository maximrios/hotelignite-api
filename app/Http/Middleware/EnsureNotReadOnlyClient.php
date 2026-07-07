<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea cualquier método de escritura (POST/PUT/PATCH/DELETE) para users de
 * tipo client. Los clients (agencias, gobiernos) tienen acceso de solo lectura.
 * Defensa en profundidad: complementa las Policies por recurso.
 */
class EnsureNotReadOnlyClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isClient() && ! $request->isMethodSafe()) {
            abort(403, 'Los clients tienen acceso de solo lectura.');
        }

        return $next($request);
    }
}
