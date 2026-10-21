<?php

namespace App\Mcp\Tools\Accommodation;

use App\AI\Services\GetAccommodationDetailsService;
use App\AI\Support\AiContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_accommodation_info')]
#[Description('Ficha de este alojamiento: descripción, dirección, servicios, habitaciones con capacidad, políticas (check-in/out, niños, mascotas, medios de pago, normas) e imágenes. Si algo no figura, no lo inventes: ofrecé dejar la consulta al alojamiento.')]
#[IsReadOnly]
class GetAccommodationInfoTool extends AccommodationTool
{
    public function __construct(private readonly GetAccommodationDetailsService $service) {}

    public function handle(Request $request): Response
    {
        return $this->respond(fn (AiContext $ctx) => $this->service->handleCurrent($ctx));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
