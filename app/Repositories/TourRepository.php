<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Resources\V1\TourResource;
use App\Http\Resources\V1\TourResourceCollection;
use App\Models\Tour;
use App\Repositories\Contracts\TourInterface;
use Illuminate\Http\Request;

class TourRepository implements TourInterface
{
    public function all(Request $request)
    {
        $query = Tour::with('images');

        // Scoping por ciudad (?city=<id>) vía pivote city_tour. Un tour puede
        // pertenecer a varias ciudades (ej. "Moldes y Guachipas").
        if ($request->filled('city')) {
            $cityId = $request->query('city');
            $query->whereHas('cities', function ($q) use ($cityId) {
                $q->where('cities.id', $cityId);
            });
        }

        $tours = $query->get();

        return new TourResourceCollection($tours);
    }

    public function find($id)
    {
        $tour = Tour::find($id);

        return new TourResource($tour);
    }
}
