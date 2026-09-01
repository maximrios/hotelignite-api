<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Esto es una API: un request sin autenticar recibe 401 con cuerpo JSON,
     * nunca un redirect. Devolver null hace que el middleware lance
     * AuthenticationException en vez de intentar redirigir.
     *
     * Antes devolvía `route('login')` cuando el request no pedía JSON, herencia
     * del scaffolding de Breeze. Al retirarlo (2026-08-30) esa ruta dejó de
     * existir y cualquier request sin `Accept: application/json` a un endpoint
     * protegido devolvía 500 «Route [login] not defined» en lugar de 401.
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
