<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Models\AccommodationType;
use App\Http\Requests\StoreAccommodationTypeRequest;
use App\Http\Requests\UpdateAccommodationTypeRequest;
use App\Http\Resources\Admin\AccommodationTypeResource;
use Illuminate\Routing\Controller as BaseController;

class AccommodationTypeController extends BaseController
{
    public function index()
    {
        $types = AccommodationType::withCount('accommodations')
            ->orderBy('name')
            ->get();

        return AccommodationTypeResource::collection($types);
    }

    public function show(AccommodationType $type)
    {
        $type->loadCount('accommodations');

        return new AccommodationTypeResource($type);
    }

    public function store(StoreAccommodationTypeRequest $request)
    {
        $type = AccommodationType::create($request->validated());

        return new AccommodationTypeResource($type);
    }

    public function update(UpdateAccommodationTypeRequest $request, AccommodationType $type)
    {
        $type->update($request->validated());

        return new AccommodationTypeResource($type->fresh());
    }

    public function destroy(AccommodationType $type)
    {
        if ($type->accommodations()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a type that has accommodations assigned.',
            ], 422);
        }

        $type->delete();

        return response()->json(['message' => 'Accommodation type deleted successfully'], 200);
    }
}
