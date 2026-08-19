<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Resources\Public\PublicAccommodationTypeResource;
use App\Http\Resources\Public\PublicCityResource;
use App\Http\Resources\Public\PublicServiceResource;
use App\Http\Resources\Public\PublicTourResource;
use App\Models\AccommodationType;
use App\Models\City;
use App\Models\Service;
use App\Models\Tour;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Datos de referencia (no scopeados por client): ciudades, tours, servicios y
 * tipos de alojamiento. Son catálogos compartidos y públicos por naturaleza.
 * Sólo lectura, con resources recortados.
 */
class CatalogController extends BaseController
{
    public function cities(Request $request)
    {
        $cities = City::query()
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', "%{$request->query('search')}%"))
            ->with('images')
            ->orderBy('name')
            ->limit((int) ($request->limit ?: 50))
            ->get();

        return PublicCityResource::collection($cities);
    }

    public function city(string $slug)
    {
        $city = City::where('slug', $slug)->with('images')->first();

        if ($city === null) {
            return response()->json(['message' => 'City not found'], 404);
        }

        return new PublicCityResource($city);
    }

    public function tours(Request $request)
    {
        $tours = Tour::query()
            ->with(['images', 'channel'])
            ->when($request->filled('city'), function ($q) use ($request) {
                $q->whereHas('cities', fn ($c) => $c->where('cities.id', $request->query('city')));
            })
            ->get();

        return PublicTourResource::collection($tours);
    }

    public function services(Request $request)
    {
        $services = Service::query()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->query('type')))
            ->orderBy('name')
            ->get();

        return PublicServiceResource::collection($services);
    }

    public function accommodationTypes()
    {
        return PublicAccommodationTypeResource::collection(
            AccommodationType::orderBy('name')->get()
        );
    }
}
