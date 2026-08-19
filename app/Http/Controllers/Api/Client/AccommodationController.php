<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Requests\CheckAvailabilityRequest;
use App\Http\Resources\Public\PublicAccommodationResource;
use App\Models\Accommodation;
use App\Models\AccommodationType;
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
}
