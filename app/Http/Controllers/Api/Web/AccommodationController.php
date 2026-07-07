<?php

namespace App\Http\Controllers\Api\Web;

use App\Models\Accommodation;
use App\Models\AccommodationType;
use App\Http\Requests\SearchAccommodationRequest;
use Illuminate\Routing\Controller as BaseController;
use App\Http\Resources\Web\AccommodationResource;
use App\Http\Resources\Web\AccommodationResourceCollection;

class AccommodationController extends BaseController
{
    public function index(SearchAccommodationRequest $request)
    {
        $limit = $request->integer('limit', 15);

        $accommodations = Accommodation::with(['city', 'state', 'type', 'images', 'plan.features'])
            ->where('enabled', true)
            ->when($request->city, fn ($q, $city) => $q->where('city_id', $city))
            ->when($request->type, function ($q, $type) {
                $slugs = explode(',', strtolower($type));
                $ids = AccommodationType::whereIn('slug', $slugs)->pluck('id');
                return $q->whereIn('type_id', $ids);
            })
            ->when($request->search, fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->paginate($limit);

        return new AccommodationResourceCollection($accommodations);
    }

    public function show(string $slug)
    {
        $accommodation = Accommodation::with([
            'city',
            'state',
            'type',
            'images',
            'plan.features',
            'services',
            'descriptions',
            'policies' => fn ($q) => $q->wherePivot('language_id', 'es'),
        ])
            ->where('slug', $slug)
            ->where('enabled', true)
            ->firstOrFail();

        return new AccommodationResource($accommodation);
    }
}
