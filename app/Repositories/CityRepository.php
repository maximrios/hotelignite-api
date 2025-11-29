<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Http\Request;
use App\Models\City;
use App\Repositories\Contracts\CityInterface;
use App\Http\Resources\V1\CityResourceCollection;

class CityRepository implements CityInterface
{
    public function all(Request $request)
    {
        $cities = City::where('enabled', 1)
            ->orderBy('name')
            ->paginate();
        return new CityResourceCollection($cities);
    }
}