<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use App\Models\RoomTypeDescription;
use App\Http\Requests\StoreRoomTypeDescriptionRequest;
use App\Http\Resources\V1\RoomTypeDescriptionResource;
use App\Http\Requests\SearchRoomTypeDescriptionRequest;
use App\Http\Requests\UpdateRoomTypeDescriptionRequest;
use App\Http\Requests\DestroyRoomTypeDescriptionRequest;
use Illuminate\Routing\Controller as BaseController;
use App\Repositories\Contracts\RoomTypeDescriptionInterface;

class RoomTypeDescriptionController extends BaseController
{
    protected RoomTypeDescriptionInterface $roomTypeDescriptionInterface;

    public function __construct(RoomTypeDescriptionInterface $roomTypeDescriptionInterface)
    {
        $this->roomTypeDescriptionInterface = $roomTypeDescriptionInterface;
    }

    public function index(SearchRoomTypeDescriptionRequest $request)
    {
        $roomTypeDescriptions = $this->roomTypeDescriptionInterface->search($request);
        return response()->json($roomTypeDescriptions, 200);
    }

    public function show($id)
    {
        $roomTypeDescription = RoomTypeDescription::with('roomType')->find($id);
        if (!$roomTypeDescription) {
            return response()->json(['message' => 'Room type description not found'], 404);
        }
        return new RoomTypeDescriptionResource($roomTypeDescription);
    }

    public function update(UpdateRoomTypeDescriptionRequest $request, $id)
    {
        $descriptions = $this->roomTypeDescriptionInterface->update($id, $request);
        return response()->json([
            'message' => 'Room type descriptions updated successfully',
            'data' => $descriptions
        ], 200);
    }

    public function store(StoreRoomTypeDescriptionRequest $request)
    {
        $descriptions = $this->roomTypeDescriptionInterface->store($request);
        return response()->json([
            'message' => 'Room type descriptions created successfully',
            'data' => $descriptions
        ], 201);
    }

    public function destroy(DestroyRoomTypeDescriptionRequest $request)
    {
        $roomTypeDescription = $this->roomTypeDescriptionInterface->destroy($request);
        return response()->json([
            'message' => 'Room type description deleted successfully',
            'data' => $roomTypeDescription
        ], 200);
    }
}

