<?php

namespace App\Http\Middleware;

use App\Models\ClientApiKey;
use App\Models\ClientApiUsage;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica consumidores B2B (portales, agencias, gobiernos) por su API key.
 *
 * Lee `Authorization: Bearer tk_live_<prefix>_<secret>`, localiza la key por su
 * prefijo visible, valida el hash y que no esté revocada ni expirada, y deja al
 * Client actuante en la request. Para reutilizar la tenencia existente
 * (`AccommodationPolicy` + `Accommodation::scopeVisibleTo`) setea un User en
 * memoria con `user_type=client` y el `client_id` correspondiente.
 */
class AuthenticateApiClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $credential = $this->extractCredential($request);

        if ($credential === null) {
            return $this->unauthorized('Falta la credencial de API.');
        }

        $prefix = $this->parsePrefix($credential);

        if ($prefix === null) {
            return $this->unauthorized('Credencial de API con formato inválido.');
        }

        $key = ClientApiKey::where('prefix', $prefix)->first();

        // Comparación en tiempo constante contra timing attacks.
        if ($key === null || ! hash_equals($key->key_hash, hash('sha256', $credential))) {
            return $this->unauthorized('Credencial de API inválida.');
        }

        if (! $key->isActive()) {
            return $this->unauthorized('Credencial de API revocada o expirada.');
        }

        $client = $key->client;

        if ($client === null || ! $client->active) {
            return $this->unauthorized('El cliente asociado a la credencial no está activo.');
        }

        // Marca de uso (best-effort, sin bloquear la request).
        $key->forceFill(['last_used_at' => now()])->saveQuietly();
        ClientApiUsage::hit($client->id);

        // User en memoria para reutilizar policies/scopes de tenencia.
        $actor = new User([
            'user_type' => User::TYPE_CLIENT,
            'client_id' => $client->id,
        ]);
        $actor->exists = false;

        $request->setUserResolver(fn () => $actor);
        Auth::setUser($actor);

        // Disponibles para el rate limiter y los checks de abilities.
        $request->attributes->set('api_client', $client);
        $request->attributes->set('api_key', $key);

        return $next($request);
    }

    /**
     * Extrae la credencial del header `Authorization: Bearer <token>`.
     */
    private function extractCredential(Request $request): ?string
    {
        $token = $request->bearerToken();

        return $token !== null && $token !== '' ? $token : null;
    }

    /**
     * Deriva el prefijo (`tk_live_<8 chars>`) de la credencial completa
     * `tk_live_<8 chars>_<secret>`. Devuelve null si no matchea el formato.
     */
    private function parsePrefix(string $credential): ?string
    {
        $parts = explode('_', $credential);

        // Estructura esperada: ['tk', 'live', '<prefix>', '<secret>'].
        if (count($parts) !== 4 || $parts[0] !== 'tk' || $parts[1] !== 'live') {
            return null;
        }

        return implode('_', array_slice($parts, 0, 3));
    }

    private function unauthorized(string $message): Response
    {
        return response()->json(['message' => $message], 401);
    }
}
