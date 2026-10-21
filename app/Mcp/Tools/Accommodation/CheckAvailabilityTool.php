<?php

namespace App\Mcp\Tools\Accommodation;

use App\AI\Exceptions\AiToolException;
use App\AI\Services\CheckAvailabilityService;
use App\AI\Support\AiContext;
use App\Mcp\Tools\StayRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('check_availability')]
#[Description('Disponibilidad y precio "desde" por noche de este alojamiento para fechas y huéspedes concretos. status: "available" (confirmado), "unavailable" (reason "capacity": ninguna habitación alcanza para el grupo; "no_availability": alguna noche sin lugar) o "unknown" (sin datos: decir que lo consultás con el alojamiento, nunca afirmar que hay lugar). source "mock" = dato de prueba: aclararlo siempre.')]
#[IsReadOnly]
class CheckAvailabilityTool extends AccommodationTool
{
    public function __construct(private readonly CheckAvailabilityService $service) {}

    public function handle(Request $request): Response
    {
        $args = $request->validate(StayRules::rules());

        return $this->respond(function (AiContext $ctx) use ($args) {
            if ($ctx->accommodation === null) {
                throw new AiToolException('El alojamiento no está disponible.');
            }

            return $this->service->handle(
                $ctx,
                (int) $ctx->accommodation->id,
                $args['checkin'],
                $args['checkout'],
                (int) $args['adults'],
                (int) ($args['children'] ?? 0),
            );
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return StayRules::schema($schema);
    }
}
