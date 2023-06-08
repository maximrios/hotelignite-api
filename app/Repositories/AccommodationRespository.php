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
        $limit = ($request->limit) ? $request->limit:10;
        $offset = ($request->offset) ? $request->offset:0;
        $type = ($request->type) ? $request->type:0;

        $accommodations = Accommodation::has('images')
                            ->when($request->city, function($q, $city) {
                                return $q->where('city_id', $city);
                            })
                            ->when($type, function($q, $type) {
                                if($type == 1) {
                                    //all stars types
                                    $hotelTypes = [1,2,3,4,5];
                                    return $q->whereIn('type_id', $hotelTypes);
                                }
                                return $q->where('type_id', $type);
                            })
                            ->offset($offset)
                            ->limit($limit)
                            ->get();
        //dd($accommodations->toSql());
        return new AccommodationResourceCollection($accommodations);
    }
    public function find($id)
    {
        $accommodation = Accommodation::find($id);
        return new AccommodationResource($accommodation);
    }
}
