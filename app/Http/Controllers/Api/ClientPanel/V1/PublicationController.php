<?php

namespace App\Http\Controllers\Api\ClientPanel\V1;

use App\Http\Requests\ClientPanel\PublishAssociatesRequest;
use App\Repositories\Contracts\PublicationInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

/**
 * El "Habilitar" del padrón: qué asociados salen en la web del client. Tenencia
 * desde el token; las reglas viven en `PublicationRepository`.
 */
class PublicationController extends BaseController
{
    public function __construct(private PublicationInterface $publication) {}

    public function update(PublishAssociatesRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json($this->publication->setPublished(
            $request->user()->client,
            $request->user(),
            $data['entity_type'],
            $data['entity_ids'],
            (bool) $data['published'],
        ));
    }
}
