<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Requests\Admin\SearchPointOfInterestRequest;
use App\Http\Requests\Admin\StorePointOfInterestRequest;
use App\Http\Requests\Admin\UpdatePointOfInterestRequest;
use App\Http\Resources\Admin\PointOfInterestResource;
use App\Http\Resources\Admin\PointOfInterestResourceCollection;
use App\Models\PointOfInterest;
use App\Repositories\Contracts\PointOfInterestInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

/**
 * Catálogo global de puntos de interés (docs/points-of-interest-plan.md).
 *
 * Lectura abierta a cualquier usuario del panel; la escritura está detrás de
 * `platform` (en el MVP sólo carga el staff desde el CRM). Las escrituras
 * devuelven el Resource envuelto en `data`, como el resto de admin/v1.
 */
class PointOfInterestController extends BaseController
{
    public function __construct(private PointOfInterestInterface $points) {}

    public function index(SearchPointOfInterestRequest $request): PointOfInterestResourceCollection
    {
        return $this->points->search($request);
    }

    public function show(PointOfInterest $poi): PointOfInterestResource
    {
        return new PointOfInterestResource($poi->load(['city.state', 'category.parent']));
    }

    public function store(StorePointOfInterestRequest $request): JsonResponse
    {
        return $this->points->store($request)->response()->setStatusCode(201);
    }

    public function update(UpdatePointOfInterestRequest $request, PointOfInterest $poi): PointOfInterestResource
    {
        return $this->points->update($poi, $request);
    }

    public function destroy(PointOfInterest $poi): JsonResponse
    {
        $this->points->remove($poi);

        return response()->json(null, 204);
    }
}
