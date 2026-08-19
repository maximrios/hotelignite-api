<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fuerza que la API responda siempre JSON, incluso ante errores de validación
 * o autenticación. Sin esto, una request sin `Accept: application/json` provoca
 * un redirect 302 en vez de un 422/401 JSON. La API de clients es
 * máquina-a-máquina: nunca debe redirigir.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
