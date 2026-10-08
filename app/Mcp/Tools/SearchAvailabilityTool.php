<?php

namespace App\Mcp\Tools;

use App\AI\Services\SearchAccommodationsService;
use App\AI\Services\SearchAvailabilityService;
use App\AI\Support\AiContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search_availability')]
#[Description('Alojamientos de un destino con lugar para fechas y huéspedes concretos, con precio "desde" por noche, en una sola llamada. Primero los confirmados ("available"), después los "unknown" (sin datos: invitar a consultar). Omite los que no tienen lugar. source "mock" = dato de prueba: aclararlo siempre.')]
#[IsReadOnly]
class SearchAvailabilityTool extends TravelerTool
{
    public function __construct(private readonly SearchAvailabilityService $service) {}

    public function handle(Request $request): Response
    {
        $args = $request->validate(StayRules::rules() + [
            'destination' => ['required', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.SearchAccommodationsService::MAX_LIMIT],
        ]);

        return $this->respond(fn (AiContext $ctx) => $this->service->handle(
            $ctx,
            $args['destination'],
            $args['checkin'],
            $args['checkout'],
            (int) $args['adults'],
            (int) ($args['children'] ?? 0),
            (int) ($args['limit'] ?? SearchAccommodationsService::MAX_LIMIT),
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'destination' => $schema->string()->required()->description('Slug del destino, obtenido de list_destinations.'),
            ...StayRules::schema($schema),
            'limit' => $schema->integer()->min(1)->max(SearchAccommodationsService::MAX_LIMIT)->description('Máximo de resultados (default 10).'),
        ];
    }
}
