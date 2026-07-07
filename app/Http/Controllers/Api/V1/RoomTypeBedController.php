<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\DestroyRoomTypeBedRequest;
use App\Http\Requests\SearchRoomTypeBedRequest;
use App\Http\Requests\StoreRoomTypeBedRequest;
use App\Http\Requests\UpdateRoomTypeBedRequest;
use App\Repositories\Contracts\RoomTypeBedInterface;
use Illuminate\Routing\Controller as BaseController;

class RoomTypeBedController extends BaseController
{
    protected RoomTypeBedInterface $roomTypeBedInterface;

    public function __construct(RoomTypeBedInterface $roomTypeBedInterface)
    {
        $this->roomTypeBedInterface = $roomTypeBedInterface;
    }

    public function index(SearchRoomTypeBedRequest $request)
    {
        return response()->json($this->roomTypeBedInterface->search($request), 200);
    }

    public function show($id)
    {
        return response()->json($this->roomTypeBedInterface->find($id), 200);
    }

    public function store(StoreRoomTypeBedRequest $request)
    {
        return response()->json($this->roomTypeBedInterface->store($request), 201);
    }

    public function update(UpdateRoomTypeBedRequest $request, $id)
    {
        return response()->json($this->roomTypeBedInterface->update($id, $request), 200);
    }

    public function destroy(DestroyRoomTypeBedRequest $request)
    {
        $bed = $this->roomTypeBedInterface->destroy($request);
        return response()->json([
            'message' => 'Room type bed deleted successfully',
            'data'    => $bed,
        ], 200);
    }
}
