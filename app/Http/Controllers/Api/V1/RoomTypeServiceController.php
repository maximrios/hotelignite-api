<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use App\Models\RoomTypeService;
use App\Http\Requests\StoreRoomTypeServiceRequest;
use App\Http\Resources\V1\RoomTypeServiceResource;
use App\Http\Requests\SearchRoomTypeServiceRequest;
use App\Http\Requests\UpdateRoomTypeServiceRequest;
use App\Http\Requests\DestroyRoomTypeServiceRequest;
use Illuminate\Routing\Controller as BaseController;
use App\Repositories\Contracts\RoomTypeServiceInterface;

class RoomTypeServiceController extends BaseController
{
    protected RoomTypeServiceInterface $roomTypeServiceInterface;

    public function __construct(RoomTypeServiceInterface $roomTypeServiceInterface)
    {
        $this->roomTypeServiceInterface = $roomTypeServiceInterface;
    }

    public function index(SearchRoomTypeServiceRequest $request)
    {
        $roomTypeServices = $this->roomTypeServiceInterface->search($request);
        return response()->json($roomTypeServices, 200);
    }

    public function show($id)
    {
        $roomTypeService = RoomTypeService::with(['roomType', 'service'])->find($id);
        if (!$roomTypeService) {
            return response()->json(['message' => 'Room type service not found'], 404);
        }
        return new RoomTypeServiceResource($roomTypeService);
    }

    public function update(UpdateRoomTypeServiceRequest $request, $id)
    {
        $services = $this->roomTypeServiceInterface->update($id, $request);
        return response()->json([
            'message' => 'Services synchronized successfully',
            'data' => $services
        ], 200);
    }

    public function store(StoreRoomTypeServiceRequest $request)
    {
        $services = $this->roomTypeServiceInterface->store($request);
        return response()->json([
            'message' => 'Services attached successfully',
            'data' => $services
        ], 201);
    }

    public function destroy(DestroyRoomTypeServiceRequest $request)
    {
        $roomTypeService = $this->roomTypeServiceInterface->destroy($request);
        return response()->json([
            'message' => 'RoomTypeService deleted successfully',
            'data' => $roomTypeService
        ], 200);
    }
}

