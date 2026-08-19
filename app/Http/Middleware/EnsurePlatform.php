<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe la ruta a super-admins de plataforma (`user_type=platform`).
 * Se usa para la gestión de clients y sus API keys bajo /api/admin/v1.
 */
class EnsurePlatform
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isPlatform()) {
            return response()->json([
                'message' => 'Acceso restringido a administradores de plataforma.',
            ], 403);
        }

        return $next($request);
    }
}
