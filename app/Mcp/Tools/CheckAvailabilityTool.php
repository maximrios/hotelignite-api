<?php

namespace App\Mcp\Tools;

use App\AI\Services\CheckAvailabilityService;
use App\AI\Support\AiContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('check_availability')]
#[Description('Disponibilidad y precio "desde" por noche de UN alojamiento para fechas y huéspedes concretos. status: "available" (confirmado), "unavailable" (reason "capacity": ninguna habitación alcanza para el grupo; "no_availability": alguna noche sin lugar) o "unknown" (sin datos: invitar a consultar al alojamiento, nunca afirmar que hay lugar). source "mock" = dato de prueba: aclararlo siempre.')]
#[IsReadOnly]
class CheckAvailabilityTool extends TravelerTool
{
    public function __construct(private readonly CheckAvailabilityService $service) {}

    public function handle(Request $request): Response
    {
        $args = $request->validate(StayRules::rules() + [
            'accommodation_id' => ['required', 'integer', 'min:1'],
        ]);

        return $this->respond(fn (AiContext $ctx) => $this->service->handle(
            $ctx,
            (int) $args['accommodation_id'],
            $args['checkin'],
            $args['checkout'],
            (int) $args['adults'],
            (int) ($args['children'] ?? 0),
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'accommodation_id' => $schema->integer()->required()->description('Id del alojamiento (campo id de search_accommodations).'),
            ...StayRules::schema($schema),
        ];
    }
}
