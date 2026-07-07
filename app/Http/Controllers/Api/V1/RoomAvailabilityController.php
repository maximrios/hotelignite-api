<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\DestroyRoomAvailabilityRequest;
use App\Http\Requests\SearchRoomAvailabilityRequest;
use App\Http\Requests\StoreRoomAvailabilityRequest;
use App\Http\Requests\UpdateRoomAvailabilityRequest;
use App\Repositories\Contracts\RoomAvailabilityInterface;
use Illuminate\Routing\Controller as BaseController;

class RoomAvailabilityController extends BaseController
{
    protected RoomAvailabilityInterface $roomAvailabilityInterface;

    public function __construct(RoomAvailabilityInterface $roomAvailabilityInterface)
    {
        $this->roomAvailabilityInterface = $roomAvailabilityInterface;
    }

    public function index(SearchRoomAvailabilityRequest $request)
    {
        return response()->json($this->roomAvailabilityInterface->search($request), 200);
    }

    public function show($id)
    {
        return response()->json($this->roomAvailabilityInterface->find($id), 200);
    }

    public function store(StoreRoomAvailabilityRequest $request)
    {
        return response()->json($this->roomAvailabilityInterface->store($request), 201);
    }

    public function update(UpdateRoomAvailabilityRequest $request, $id)
    {
        return response()->json($this->roomAvailabilityInterface->update($id, $request), 200);
    }

    public function destroy(DestroyRoomAvailabilityRequest $request)
    {
        $availability = $this->roomAvailabilityInterface->destroy($request);
        return response()->json([
            'message' => 'Room availability deleted successfully',
            'data' => $availability,
        ], 200);
    }
}
