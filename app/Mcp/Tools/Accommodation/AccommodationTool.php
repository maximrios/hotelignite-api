<?php

namespace App\Mcp\Tools\Accommodation;

use App\AI\Exceptions\AiToolException;
use App\AI\Support\AiContext;
use Closure;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Base de las herramientas del MCP del alojamiento
 * (docs/accommodation-mcp-plan.md). Igual que `TravelerTool`, pero el contexto
 * sale de `auth.accommodation` y trae el alojamiento fijado: ninguna herramienta
 * recibe un id de alojamiento del modelo.
 */
abstract class AccommodationTool extends Tool
{
    /**
     * Una herramienta sin argumentos sale con `"properties": []` (array JSON):
     * `laravel/mcp` normaliza con `??=`, que no pisa un array vacío. La API de
     * Anthropic y otros clientes estrictos rechazan ese schema; tiene que ser `{}`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $tool = parent::toArray();

        if (($tool['inputSchema']['properties'] ?? null) === []) {
            $tool['inputSchema']['properties'] = (object) [];
        }

        return $tool;
    }

    /**
     * @param  Closure(AiContext): array<string, mixed>  $callback
     */
    protected function respond(Closure $callback): Response
    {
        try {
            return Response::json($callback(AiContext::fromAccommodationRequest(request())));
        } catch (AiToolException $e) {
            return Response::error($e->getMessage());
        }
    }
}
