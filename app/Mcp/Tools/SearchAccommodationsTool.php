<?php

namespace App\Mcp\Tools;

use App\AI\Services\SearchAccommodationsService;
use App\AI\Support\AiContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search_accommodations')]
#[Description('Busca alojamientos por destino, tipo y texto libre, sin fechas. Para saber si hay lugar en fechas concretas usá search_availability o check_availability.')]
#[IsReadOnly]
class SearchAccommodationsTool extends TravelerTool
{
    public function __construct(private readonly SearchAccommodationsService $service) {}

    public function handle(Request $request): Response
    {
        $args = $request->validate([
            'destination' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'max:60'],
            'query' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.SearchAccommodationsService::MAX_LIMIT],
        ]);

        return $this->respond(fn (AiContext $ctx) => $this->service->handle(
            $ctx,
            $args['destination'] ?? null,
            $args['type'] ?? null,
            $args['query'] ?? null,
            (int) ($args['limit'] ?? SearchAccommodationsService::MAX_LIMIT),
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'destination' => $schema->string()->description('Slug del destino, obtenido de list_destinations (ej. "salta-capital").'),
            'type' => $schema->string()->description('Tipo de alojamiento, texto parcial: "hostal", "4 estrellas", "cabañas", "boutique".'),
            'query' => $schema->string()->description('Texto libre sobre nombre, servicios y descripción: "pileta", "spa", "centro".'),
            'limit' => $schema->integer()->min(1)->max(SearchAccommodationsService::MAX_LIMIT)->description('Máximo de resultados (default 10).'),
        ];
    }
}
