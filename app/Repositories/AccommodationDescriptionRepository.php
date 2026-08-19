<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Requests\StoreAccommodationDescriptionRequest;
use App\Http\Requests\UpdateAccommodationDescriptionRequest;
use App\Http\Resources\V1\AccommodationDescriptionResource;
use App\Models\AccommodationDescription;
use App\Repositories\Contracts\AccommodationDescriptionInterface;
use Illuminate\Http\Request;

class AccommodationDescriptionRepository implements AccommodationDescriptionInterface
{
    public function all(Request $request)
    {
        $descriptions = AccommodationDescription::when($request->accommodation_id, function ($q, $id) {
            return $q->where('accommodation_id', $id);
        })
            ->when($request->language_id, function ($q, $lang) {
                return $q->where('language_id', $lang);
            })
            ->get();

        return AccommodationDescriptionResource::collection($descriptions);
    }

    public function find($id)
    {
        $description = AccommodationDescription::findOrFail($id);

        return new AccommodationDescriptionResource($description);
    }

    public function store(StoreAccommodationDescriptionRequest $request)
    {
        $description = AccommodationDescription::updateOrCreate(
            [
                'accommodation_id' => $request->accommodation_id,
                'language_id' => $request->language_id,
            ],
            [
                'introduction' => $request->introduction,
                'description' => $request->description,
                'enabled' => $request->enabled ?? true,
            ]
        );

        return new AccommodationDescriptionResource($description);
    }

    public function update($id, UpdateAccommodationDescriptionRequest $request)
    {
        $description = AccommodationDescription::findOrFail($id);
        $description->update($request->only(['language_id', 'introduction', 'description', 'enabled']));

        return new AccommodationDescriptionResource($description);
    }

    public function destroy(Request $request)
    {
        $description = AccommodationDescription::findOrFail($request->accommodation_description_id);
        $description->delete();

        return new AccommodationDescriptionResource($description);
    }
}
