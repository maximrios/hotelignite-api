<?php

namespace App\Http\Middleware;

use App\Models\AccommodationApiKey;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica a un agente externo que contesta en nombre de un alojamiento
 * (docs/accommodation-mcp-plan.md). Gemelo de `AuthenticateApiClient`, con la
 * credencial `ak_live_<prefix>_<secret>`.
 *
 * También acepta la misma key partida en `client_id.client_secret`
 * (`ak_live_<prefix>.<secret>`): es como arma el Bearer Colmexa, que guarda
 * cada conector MCP como un par client_id / client_secret y los une con un
 * punto. Es la misma credencial con otro separador, no una segunda.
 *
 * Además de la key, revalida en cada request lo que puede cambiar después de
 * emitirla: que la cuenta exista, que el alojamiento siga siendo de esa cuenta y
 * esté habilitado, y que su plan incluya el asistente. Bajar de plan apaga el
 * asistente sin revocar nada.
 *
 * Deja un User en memoria con `user_type=account` para reutilizar
 * `Accommodation::scopeVisibleTo()`. Ojo: ese actor ve TODA la cuenta; quien
 * consuma esto se tiene que acotar a `api_accommodation`.
 */
class AuthenticateAccommodationKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $credential = $request->bearerToken();

        if ($credential === null || $credential === '') {
            return $this->unauthorized('Falta la credencial de API.');
        }

        $credential = $this->canonical($credential);

        $prefix = $this->parsePrefix($credential);

        if ($prefix === null) {
            return $this->unauthorized('Credencial de API con formato inválido.');
        }

        $key = AccommodationApiKey::with(['account', 'accommodation.plan'])->where('prefix', $prefix)->first();

        // Comparación en tiempo constante contra timing attacks.
        if ($key === null || ! hash_equals($key->key_hash, hash('sha256', $credential))) {
            return $this->unauthorized('Credencial de API inválida.');
        }

        if (! $key->isActive()) {
            return $this->unauthorized('Credencial de API revocada o expirada.');
        }

        $accommodation = $key->accommodation;

        if (
            $key->account === null
            || $accommodation === null
            || (int) $accommodation->account_id !== (int) $key->account_id
            || ! $accommodation->enabled
        ) {
            return $this->unauthorized('El alojamiento de la credencial no está disponible.');
        }

        if (! $accommodation->hasFeature(AccommodationApiKey::FEATURE)) {
            return response()->json([
                'message' => 'El plan del alojamiento no incluye el asistente.',
            ], 403);
        }

        // Marca de uso (best-effort, sin bloquear la request).
        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        $actor = new User([
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => $key->account_id,
        ]);
        $actor->exists = false;

        $request->setUserResolver(fn () => $actor);
        Auth::setUser($actor);

        // Disponibles para el rate limiter, el check de abilities y el MCP.
        $request->attributes->set('api_accommodation', $accommodation);
        $request->attributes->set('api_key', $key);

        return $next($request);
    }

    /**
     * Deriva el prefijo (`ak_live_<8 chars>`) de la credencial completa
     * `ak_live_<8 chars>_<secret>`. Devuelve null si no matchea el formato.
     */
    /**
     * `ak_live_<prefix>.<secret>` → `ak_live_<prefix>_<secret>`, que es lo que
     * se hasheó al emitirla. Cualquier otra cosa pasa tal cual y la rechaza
     * `parsePrefix()`.
     */
    private function canonical(string $credential): string
    {
        return preg_match('/^(ak_live_[a-z0-9]+)\.([A-Za-z0-9]+)$/', $credential, $m) === 1
            ? $m[1].'_'.$m[2]
            : $credential;
    }

    private function parsePrefix(string $credential): ?string
    {
        $parts = explode('_', $credential);

        // Estructura esperada: ['ak', 'live', '<prefix>', '<secret>'].
        if (count($parts) !== 4 || $parts[0] !== 'ak' || $parts[1] !== 'live') {
            return null;
        }

        return implode('_', array_slice($parts, 0, 3));
    }

    private function unauthorized(string $message): Response
    {
        return response()->json(['message' => $message], 401);
    }
}
