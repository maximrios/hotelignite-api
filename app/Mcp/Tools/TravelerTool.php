<?php

namespace App\Mcp\Tools;

use App\AI\Exceptions\AiToolException;
use App\AI\Support\AiContext;
use Closure;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Base de las herramientas del MCP del viajero. Las herramientas son adapters
 * finos: validan argumentos, arman el `AiContext` desde la request HTTP (que ya
 * pasó por `auth.client`) y delegan en un servicio de `App\AI`.
 *
 * El resultado es JSON en un content de texto; los errores esperables
 * (`AiToolException`) vuelven como resultado con error para que el modelo los
 * lea y corrija, no como falla del servidor.
 */
abstract class TravelerTool extends Tool
{
    /**
     * @param  Closure(AiContext): array<string, mixed>  $callback
     */
    protected function respond(Closure $callback): Response
    {
        try {
            return Response::json($callback(AiContext::fromHttpRequest(request())));
        } catch (AiToolException $e) {
            return Response::error($e->getMessage());
        }
    }
}
