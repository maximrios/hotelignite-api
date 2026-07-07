<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Http\Request;
use App\Models\RoomTypeBed;
use App\Http\Requests\StoreRoomTypeBedRequest;
use App\Http\Requests\UpdateRoomTypeBedRequest;
use App\Http\Requests\DestroyRoomTypeBedRequest;
use App\Http\Resources\V1\RoomTypeBedResource;
use App\Http\Resources\V1\RoomTypeBedResourceCollection;
use App\Repositories\Contracts\RoomTypeBedInterface;

class RoomTypeBedRepository implements RoomTypeBedInterface
{
    public function all(Request $request)
    {
        $limit  = $request->limit  ?: 10;
        $offset = $request->offset ?: 0;

        $beds = RoomTypeBed::when($request->room_type_id, fn ($q, $v) => $q->where('room_type_id', $v))
                           ->when($request->type, fn ($q, $v) => $q->where('type', $v))
                           ->offset($offset)
                           ->limit($limit)
                           ->get();

        return new RoomTypeBedResourceCollection($beds);
    }

    public function find($id)
    {
        $bed = RoomTypeBed::with('roomType')->find($id);
        return new RoomTypeBedResource($bed);
    }

    public function search($request)
    {
        $limit = $request->limit ?: 10;

        $beds = RoomTypeBed::when($request->room_type_id, fn ($q, $v) => $q->where('room_type_id', $v))
                           ->when($request->type, fn ($q, $v) => $q->where('type', $v))
                           ->paginate($limit);

        return new RoomTypeBedResourceCollection($beds);
    }

    public function store(StoreRoomTypeBedRequest $request): RoomTypeBedResource
    {
        $bed = RoomTypeBed::create($request->all());
        return new RoomTypeBedResource($bed->load('roomType'));
    }

    public function update($id, UpdateRoomTypeBedRequest $request): RoomTypeBedResource
    {
        $bed = RoomTypeBed::find($id);
        $bed->update($request->all());
        return new RoomTypeBedResource($bed->load('roomType'));
    }

    public function destroy(DestroyRoomTypeBedRequest $request): RoomTypeBedResource
    {
        $bed = RoomTypeBed::find($request->room_type_bed_id);
        $bed->delete();
        return new RoomTypeBedResource($bed);
    }
}
