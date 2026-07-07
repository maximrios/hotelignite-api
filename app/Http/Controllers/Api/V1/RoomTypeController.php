<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use App\Models\RoomType;
use App\Http\Requests\StoreRoomTypeRequest;
use App\Http\Resources\V1\RoomTypeResource;
use App\Http\Requests\SearchRoomTypeRequest;
use App\Http\Requests\UpdateRoomTypeRequest;
use App\Http\Requests\DestroyRoomTypeRequest;
use Illuminate\Routing\Controller as BaseController;
use App\Repositories\Contracts\RoomTypeInterface;

class RoomTypeController extends BaseController
{

    protected RoomTypeInterface $roomTypeInterface;

    public function __construct(RoomTypeInterface $roomTypeInterface)
    {
        $this->roomTypeInterface = $roomTypeInterface;
    }

    public function index(SearchRoomTypeRequest $request)
    {
        $roomTypes = $this->roomTypeInterface->search($request);
        return response()->json($roomTypes, 200);
    }

    public function show($id)
    {
        $roomType = $this->roomTypeInterface->find($id);
        return response()->json($roomType, 200);
    }

    public function update(UpdateRoomTypeRequest $request, $id)
    {
        $roomType = $this->roomTypeInterface->update($id, $request);
        return response()->json($roomType, 200);
    }

    public function store(StoreRoomTypeRequest $request)
    {
        $roomType = $this->roomTypeInterface->store($request);
        return response()->json($roomType, 200);
    }

    public function destroy(DestroyRoomTypeRequest $request)
    {
        $roomType = $this->roomTypeInterface->destroy($request);
        return response()->json([
            'message' => 'Room type deleted successfully',
            'data' => $roomType
        ], 200);
    }
}

