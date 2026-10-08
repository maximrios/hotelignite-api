<?php

namespace App\AI\Support;

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Quién pregunta y en qué idioma. Lo arma el adapter (servidor MCP) a partir de
 * lo que dejó `auth.client` en la request; los servicios de `App\AI` lo reciben
 * en lugar de `$request->user()` y nunca ven la request HTTP.
 */
final class AiContext
{
    public function __construct(
        public readonly User $actor,
        public readonly Client $client,
        public readonly string $language,
    ) {}

    /**
     * Construye el contexto desde una request que ya pasó por `auth.client`.
     */
    public static function fromHttpRequest(Request $request): self
    {
        $client = $request->attributes->get('api_client');
        $actor = $request->user();

        if (! $client instanceof Client || ! $actor instanceof User) {
            throw new RuntimeException('AiContext requiere una request autenticada con auth.client.');
        }

        return new self($actor, $client, self::resolveLanguage($request->header('Accept-Language')));
    }

    /**
     * Toma el primer idioma de `Accept-Language` que esté soportado (solo la
     * subetiqueta primaria: `es-AR` → `es`). Sin coincidencia, el default.
     */
    public static function resolveLanguage(?string $header): string
    {
        $supported = (array) config('ai.languages', ['es']);
        $default = (string) config('ai.default_language', 'es');

        if ($header === null || $header === '') {
            return $default;
        }

        foreach (explode(',', $header) as $part) {
            $tag = strtolower(trim(explode(';', $part)[0]));
            $primary = explode('-', $tag)[0];

            if (in_array($primary, $supported, true)) {
                return $primary;
            }
        }

        return $default;
    }
}
