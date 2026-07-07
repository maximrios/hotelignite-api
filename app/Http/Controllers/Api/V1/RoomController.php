<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\DestroyRoomRequest;
use App\Http\Requests\SearchRoomRequest;
use App\Http\Requests\StoreRoomRequest;
use App\Http\Requests\UpdateRoomRequest;
use App\Repositories\Contracts\RoomInterface;
use Illuminate\Routing\Controller as BaseController;

class RoomController extends BaseController
{
    protected RoomInterface $roomInterface;

    public function __construct(RoomInterface $roomInterface)
    {
        $this->roomInterface = $roomInterface;
    }

    public function index(SearchRoomRequest $request)
    {
        return response()->json($this->roomInterface->search($request), 200);
    }

    public function show($id)
    {
        return response()->json($this->roomInterface->find($id), 200);
    }

    public function store(StoreRoomRequest $request)
    {
        $room = $this->roomInterface->store($request);
        return response()->json($room, 201);
    }

    public function update(UpdateRoomRequest $request, $id)
    {
        return response()->json($this->roomInterface->update($id, $request), 200);
    }

    public function destroy(DestroyRoomRequest $request)
    {
        $room = $this->roomInterface->destroy($request);
        return response()->json([
            'message' => 'Room deleted successfully',
            'data' => $room,
        ], 200);
    }
}
