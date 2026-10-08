<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Requests\Admin\NearbyPointsRequest;
use App\Http\Requests\CheckAvailabilityRequest;
use App\Http\Resources\Admin\PointOfInterestResource;
use App\Http\Resources\Public\PublicAccommodationResource;
use App\Http\Resources\Public\PublicPointOfInterestResource;
use App\Models\Accommodation;
use App\Models\AccommodationType;
use App\Repositories\Contracts\PointOfInterestInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Catálogo de accommodations para consumidores B2B. Siempre scopeado a los
 * accommodations relacionados con el client (pivote `accommodation_client`) vía
 * `Accommodation::scopeVisibleTo`, usando el User en memoria que dejó
 * `AuthenticateApiClient`. Sólo lectura.
 */
class AccommodationController extends BaseController
{
    private const EAGER = [
        'state',
        'city',
        'type',
        'images',
        'services',
        'roomTypes.images',
        'roomTypes.descriptions',
    ];

    public function index(Request $request)
    {
        $limit = (int) ($request->limit ?: 10);

        $accommodations = Accommodation::visibleTo($request->user())
            ->with(self::EAGER)
            ->withCount('rooms')
            ->when($request->filled('city'), fn ($q) => $q->where('city_id', $request->query('city')))
            ->when($request->filled('type'), function ($q) use ($request) {
                $slugs = explode(',', strtolower((string) $request->query('type')));
                $ids = AccommodationType::whereIn('slug', $slugs)->pluck('id');

                return $q->whereIn('type_id', $ids);
            })
            ->where('enabled', 1)
            ->paginate($limit);

        return PublicAccommodationResource::collection($accommodations);
    }

    public function show(Request $request, string $slug)
    {
        $accommodation = Accommodation::visibleTo($request->user())
            ->with(self::EAGER)
            ->withCount('rooms')
            ->where('slug', $slug)
            ->first();

        if ($accommodation === null) {
            return response()->json(['message' => 'Accommodation not found'], 404);
        }

        return new PublicAccommodationResource($accommodation);
    }

    /**
     * Disponibilidad de un accommodation visible para el client. Reutiliza la
     * lógica de `AccommodationAvailabilityController` tras verificar la tenencia.
     */
    public function availability(CheckAvailabilityRequest $request, int|string $id)
    {
        $visible = Accommodation::visibleTo($request->user())
            ->whereKey($id)
            ->exists();

        if (! $visible) {
            return response()->json(['message' => 'Accommodation not found'], 404);
        }

        return app(\App\Http\Controllers\Api\V1\AccommodationAvailabilityController::class)
            ->check($request, $id);
    }

    /**
     * "Qué hay alrededor": puntos de interés cercanos a un accommodation visible
     * para el client, agrupados por categoría raíz. Misma búsqueda que el
     * `nearby-points` del panel, con la forma pública del POI.
     */
    public function nearbyPoints(NearbyPointsRequest $request, string $slug, PointOfInterestInterface $points): JsonResponse
    {
        $accommodation = Accommodation::visibleTo($request->user())
            ->where('slug', $slug)
            ->first();

        if ($accommodation === null) {
            return response()->json(['message' => 'Accommodation not found'], 404);
        }

        $nearby = $points->nearby($accommodation, $request->integer('limit_per_group', 5));

        $nearby['groups'] = array_map(fn (array $group) => [
            'category' => [
                'slug' => $group['category']->slug,
                'name' => $group['category']->name,
                'icon' => $group['category']->icon,
            ],
            'items' => array_map(
                fn (PointOfInterestResource $item) => (new PublicPointOfInterestResource($item->resource))->resolve($request),
                $group['items'],
            ),
        ], $nearby['groups']);

        return response()->json(['data' => $nearby]);
    }
}
