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
#[Description('Ficha de este alojamiento: categoría (estrellas), descripción, dirección y cómo llegar (referencia y link de mapa), servicios, habitaciones (capacidad, camas, tamaño y servicios de cada una), políticas (check-in/out, niños, mascotas, fumar, fiestas, medios de pago, normas), condiciones de cancelación por tarifa, extras que se pagan aparte (cochera, desayuno, traslado…: precio, si es por noche/persona/estadía y si depende de disponibilidad), lugares cercanos e imágenes. Si algo no figura, no lo inventes: ofrecé dejar la consulta al alojamiento.')]
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
