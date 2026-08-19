<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe la ruta a usuarios de tipo `client` (agencias, gobiernos, cámaras)
 * con un `client_id` colgado. Es la puerta del grupo `client-panel/v1`, donde
 * un client emite invitaciones y ve su padrón.
 *
 * A diferencia de `client.readonly`, este grupo SÍ permite escrituras: un client
 * necesita crear invitaciones. La tenencia sale del token (`$user->client_id`),
 * nunca de la URL (§12 de la skill).
 */
class EnsureClientUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isClient() || ! $user->client_id) {
            return response()->json([
                'message' => 'Acceso restringido a usuarios de client.',
            ], 403);
        }

        return $next($request);
    }
}
