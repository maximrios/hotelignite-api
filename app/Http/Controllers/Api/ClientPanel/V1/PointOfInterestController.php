<?php

namespace App\Http\Controllers\Api\ClientPanel\V1;

use App\Http\Requests\Admin\SearchPointOfInterestRequest;
use App\Http\Requests\ClientPanel\StorePointOfInterestRequest;
use App\Http\Requests\ClientPanel\UpdatePointOfInterestRequest;
use App\Http\Resources\Admin\PointOfInterestResource;
use App\Http\Resources\Admin\PointOfInterestResourceCollection;
use App\Models\PointOfInterest;
use App\Repositories\Contracts\PointOfInterestInterface;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

/**
 * Puntos de interés que carga un client B2B (docs/events-plan.md). El listado
 * son los propios; editar y borrar, sólo lo propio (`CatalogContentPolicy`). El
 * `client_id` sale del token, nunca del cuerpo.
 *
 * Puede cargar en cualquier ciudad: la jurisdicción por client es fase 2.
 */
class PointOfInterestController extends BaseController
{
    use AuthorizesRequests;

    public function __construct(private PointOfInterestInterface $points) {}

    public function index(SearchPointOfInterestRequest $request): PointOfInterestResourceCollection
    {
        return $this->points->search($request, (int) $request->user()->client_id);
    }

    public function show(PointOfInterest $poi): PointOfInterestResource
    {
        $this->authorize('update', $poi);

        return new PointOfInterestResource($poi->load(['city.state', 'category.parent', 'client']));
    }

    public function store(StorePointOfInterestRequest $request): JsonResponse
    {
        $this->authorize('create', PointOfInterest::class);

        return $this->points->store($request->validated(), (int) $request->user()->client_id)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdatePointOfInterestRequest $request, PointOfInterest $poi): PointOfInterestResource
    {
        $this->authorize('update', $poi);

        return $this->points->update($poi, $request->validated());
    }

    public function destroy(PointOfInterest $poi): JsonResponse
    {
        $this->authorize('delete', $poi);

        $this->points->remove($poi);

        return response()->json(null, 204);
    }
}
