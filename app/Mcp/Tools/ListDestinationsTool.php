<?php

namespace App\Mcp\Tools;

use App\AI\Services\ListDestinationsService;
use App\AI\Support\AiContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_destinations')]
#[Description('Lista los destinos (ciudades) donde hay alojamientos, con su slug y cantidad de alojamientos. Usala para convertir el nombre de un lugar que menciona el viajero en el slug que piden las demás herramientas.')]
#[IsReadOnly]
class ListDestinationsTool extends TravelerTool
{
    public function __construct(private readonly ListDestinationsService $service) {}

    public function handle(Request $request): Response
    {
        $args = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
        ]);

        return $this->respond(fn (AiContext $ctx) => $this->service->handle($ctx, $args['search'] ?? null));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->max(80)->description('Parte del nombre de la ciudad, por ejemplo "cafayate". Vacío lista todas.'),
        ];
    }
}
