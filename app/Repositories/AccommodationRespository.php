<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Http\Request;
use App\Models\Accommodation;
use App\Http\Requests\StoreAccommodationRequest;
use App\Http\Resources\V1\AccommodationResource;
use App\Http\Requests\DestroyAccommodationRequest;
use App\Http\Requests\UpdateAccommodationRequest;
use App\Repositories\Contracts\AccommodationInterface;
use App\Http\Resources\V1\AccommodationResourceCollection;
use App\Models\AccommodationType;

class AccommodationRespository implements AccommodationInterface
{
    public function all(Request $request)
    {
        //$request->city = ($request->city) ? $request->city:0;
        $limit = ($request->limit) ? $request->limit : 10;
        $offset = ($request->offset) ? $request->offset : 0;
        $type = ($request->type) ? $request->type : 0;

        $accommodations = Accommodation::has('images')
                            ->when($request->city, function ($q, $city) {
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

        return new AccommodationResourceCollection($accommodations);
    }
    public function find($id)
    {
        $accommodation = Accommodation::find($id);
        return new AccommodationResource($accommodation);
    }

    public function search($request)
    {
        $limit = ($request->limit) ? $request->limit : 10;
        $offset = ($request->offset) ? $request->offset : 0;
        $type = ($request->type) ? $request->type : 0;
        $city = ($request->city) ? $request->city : 0;

        $accommodations = Accommodation::when($city, function ($q, $city) {
                                return $q->where('city_id', $city);
                            })
                            ->when($type, function ($q, $type) {
                                $slugs = explode(',', strtolower($type));
                                $ids = AccommodationType::whereIn('slug', $slugs)->pluck('id');
                                return $q->whereIn('type_id', $ids);
                            })
                            //->offset($offset)
                            //->limit($limit)
                            ->paginate();

        return new AccommodationResourceCollection($accommodations);
    }

    public function update($id, UpdateAccommodationRequest $request): AccommodationResource
    {
        $accommodation = Accommodation::find($id);
        $accommodation->update($request->all());
        return new AccommodationResource($accommodation);
    }

    public function store(StoreAccommodationRequest $request): AccommodationResource
    {
        $accommodation = Accommodation::create($request->all());
        return new AccommodationResource($accommodation);
    }

    public function destroy(DestroyAccommodationRequest $request): AccommodationResource
    {
        $accommodation = Accommodation::find($request->accommodation_id);
        $accommodation->delete();
        return new AccommodationResource($accommodation);
    }
}
