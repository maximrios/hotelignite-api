<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Http\Request;
use App\Models\Accommodation;
use App\Http\Resources\V1\AccommodationResource;
use App\Repositories\Contracts\AccommodationInterface;
use App\Http\Resources\V1\AccommodationResourceCollection;

class AccommodationRespository implements AccommodationInterface
{
    public function all(Request $request)
    {
        //$request->city = ($request->city) ? $request->city:0;
        $request->limit = ($request->limit) ? $request->limit:10;
        $request->offset = ($request->offset) ? $request->offset:0;

        $accommodations = Accommodation::has('images')
                            ->when($request->city, function($q, $city) {
                                return $q->where('city_id', $city);
                            })
                            ->when($request->type, function($q, $type) {
                                return $q->where('type_id', $type);
                            })
                            ->offset($request->offset)
                            ->limit($request->limit)
                            ->get();
        return new AccommodationResourceCollection($accommodations);
    }
    public function find($id)
    {
        $accommodation = Accommodation::find($id);
        return new AccommodationResource($accommodation);
    }
}
