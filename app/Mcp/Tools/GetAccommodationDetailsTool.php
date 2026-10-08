<?php

namespace App\Mcp\Tools;

use App\AI\Services\GetAccommodationDetailsService;
use App\AI\Support\AiContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_accommodation_details')]
#[Description('Ficha completa de un alojamiento: descripción, servicios, habitaciones con capacidad, políticas (check-in/out, niños, mascotas, pagos) e imágenes. Si una política no figura, no la inventes: sugerí consultarla.')]
#[IsReadOnly]
class GetAccommodationDetailsTool extends TravelerTool
{
    public function __construct(private readonly GetAccommodationDetailsService $service) {}

    public function handle(Request $request): Response
    {
        $args = $request->validate([
            'slug' => ['required', 'string', 'max:200'],
        ]);

        return $this->respond(fn (AiContext $ctx) => $this->service->handle($ctx, $args['slug']));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->required()->description('Slug del alojamiento, obtenido de search_accommodations o search_availability.'),
        ];
    }
}
