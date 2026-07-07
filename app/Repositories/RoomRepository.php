<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Http\Request;
use App\Models\Room;
use App\Http\Requests\StoreRoomRequest;
use App\Http\Requests\UpdateRoomRequest;
use App\Http\Requests\DestroyRoomRequest;
use App\Http\Resources\V1\RoomResource;
use App\Http\Resources\V1\RoomResourceCollection;
use App\Repositories\Contracts\RoomInterface;

class RoomRepository implements RoomInterface
{
    public function all(Request $request)
    {
        $limit = $request->limit ?: 10;
        $offset = $request->offset ?: 0;

        $rooms = Room::when($request->accommodation_id, function ($q, $accommodation_id) {
                        return $q->where('accommodation_id', $accommodation_id);
                    })
                    ->when($request->room_type_id, function ($q, $room_type_id) {
                        return $q->where('room_type_id', $room_type_id);
                    })
                    ->when($request->status, function ($q, $status) {
                        return $q->where('status', $status);
                    })
                    ->with(['roomType', 'accommodation'])
                    ->offset($offset)
                    ->limit($limit)
                    ->get();

        return new RoomResourceCollection($rooms);
    }

    public function find($id)
    {
        $room = Room::with(['roomType', 'accommodation'])->find($id);
        return new RoomResource($room);
    }

    public function search($request)
    {
        $limit = $request->limit ?: 10;

        $rooms = Room::when($request->accommodation_id, function ($q, $accommodation_id) {
                        return $q->where('accommodation_id', $accommodation_id);
                    })
                    ->when($request->room_type_id, function ($q, $room_type_id) {
                        return $q->where('room_type_id', $room_type_id);
                    })
                    ->when($request->status, function ($q, $status) {
                        return $q->where('status', $status);
                    })
                    ->with(['roomType', 'accommodation'])
                    ->paginate($limit);

        return new RoomResourceCollection($rooms);
    }

    public function store(StoreRoomRequest $request): RoomResource
    {
        $room = Room::create($request->all());
        return new RoomResource($room->load(['roomType', 'accommodation']));
    }

    public function update($id, UpdateRoomRequest $request): RoomResource
    {
        $room = Room::find($id);
        $room->update($request->all());
        return new RoomResource($room->load(['roomType', 'accommodation']));
    }

    public function destroy(DestroyRoomRequest $request): RoomResource
    {
        $room = Room::find($request->room_id);
        $room->delete();
        return new RoomResource($room);
    }
}
