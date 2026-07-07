<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use App\Http\Requests\StoreAccommodationDescriptionRequest;
use App\Http\Requests\UpdateAccommodationDescriptionRequest;
use Illuminate\Routing\Controller as BaseController;
use App\Repositories\Contracts\AccommodationDescriptionInterface;

class AccommodationDescriptionController extends BaseController
{
    protected AccommodationDescriptionInterface $accommodationDescriptionInterface;

    public function __construct(AccommodationDescriptionInterface $accommodationDescriptionInterface)
    {
        $this->accommodationDescriptionInterface = $accommodationDescriptionInterface;
    }

    public function index(Request $request)
    {
        $descriptions = $this->accommodationDescriptionInterface->all($request);
        return response()->json($descriptions, 200);
    }

    public function show($id)
    {
        $description = $this->accommodationDescriptionInterface->find($id);
        return response()->json($description, 200);
    }

    public function store(StoreAccommodationDescriptionRequest $request)
    {
        $description = $this->accommodationDescriptionInterface->store($request);
        return response()->json([
            'message' => 'Accommodation description saved successfully',
            'data'    => $description,
        ], 201);
    }

    public function update(UpdateAccommodationDescriptionRequest $request, $id)
    {
        $description = $this->accommodationDescriptionInterface->update($id, $request);
        return response()->json([
            'message' => 'Accommodation description updated successfully',
            'data'    => $description,
        ], 200);
    }

    public function destroy(Request $request)
    {
        $description = $this->accommodationDescriptionInterface->destroy($request);
        return response()->json([
            'message' => 'Accommodation description deleted successfully',
            'data'    => $description,
        ], 200);
    }
}
