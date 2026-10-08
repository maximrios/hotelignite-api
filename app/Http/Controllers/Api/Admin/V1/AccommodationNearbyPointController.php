<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Requests\Admin\NearbyPointsRequest;
use App\Models\Accommodation;
use App\Repositories\Contracts\PointOfInterestInterface;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

/**
 * "En los alrededores": puntos de interés cercanos a un alojamiento, agrupados
 * por categoría raíz. Tenencia vía `AccommodationPolicy@view`.
 */
class AccommodationNearbyPointController extends BaseController
{
    use AuthorizesRequests;

    private const DEFAULT_LIMIT_PER_GROUP = 5;

    public function __construct(private PointOfInterestInterface $points) {}

    public function index(NearbyPointsRequest $request, Accommodation $accommodation): JsonResponse
    {
        $this->authorize('view', $accommodation);

        $limit = $request->integer('limit_per_group', self::DEFAULT_LIMIT_PER_GROUP);

        return response()->json(['data' => $this->points->nearby($accommodation, $limit)]);
    }
}
