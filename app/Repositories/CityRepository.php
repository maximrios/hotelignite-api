<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Resources\V1\CityResource;
use App\Http\Resources\V1\CityResourceCollection;
use App\Models\City;
use App\Repositories\Contracts\CityInterface;
use Illuminate\Http\Request;

class CityRepository implements CityInterface
{
    public function all(Request $request)
    {
        $limit = $request->limit ?? 20;

        $cities = City::when($request->search, fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return new CityResourceCollection($cities);
    }

    public function show(string $slug): mixed
    {
        $city = City::where('slug', $slug)->with('images')->firstOrFail();

        return new CityResource($city);
    }
}
