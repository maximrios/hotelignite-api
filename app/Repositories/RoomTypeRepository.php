<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Http\Request;
use App\Models\RoomType;
use App\Http\Requests\StoreRoomTypeRequest;
use App\Http\Resources\V1\RoomTypeResource;
use App\Http\Requests\DestroyRoomTypeRequest;
use App\Http\Requests\UpdateRoomTypeRequest;
use App\Repositories\Contracts\RoomTypeInterface;
use App\Http\Resources\V1\RoomTypeResourceCollection;

class RoomTypeRepository implements RoomTypeInterface
{
    public function all(Request $request)
    {
        $limit = ($request->limit) ? $request->limit : 10;
        $offset = ($request->offset) ? $request->offset : 0;

        $roomTypes = RoomType::when($request->accommodation_id, function ($q, $accommodation_id) {
                            return $q->where('accommodation_id', $accommodation_id);
                        })
                        ->when($request->category_id, function ($q, $category_id) {
                            return $q->where('category_id', $category_id);
                        })
                        ->with(['accommodation', 'images', 'category', 'descriptions'])
                        ->offset($offset)
                        ->limit($limit)
                        ->get();

        return new RoomTypeResourceCollection($roomTypes);
    }

    public function find($id)
    {
        $roomType = RoomType::with(['accommodation', 'images', 'category', 'descriptions'])->find($id);
        return new RoomTypeResource($roomType);
    }

    public function search($request)
    {
        $limit = ($request->limit) ? $request->limit : 10;
        $offset = ($request->offset) ? $request->offset : 0;

        $roomTypes = RoomType::when($request->accommodation_id, function ($q, $accommodation_id) {
                            return $q->where('accommodation_id', $accommodation_id);
                        })
                        ->when($request->category_id, function ($q, $category_id) {
                            return $q->where('category_id', $category_id);
                        })
                        ->when($request->status, function ($q, $status) {
                            return $q->where('status', $status);
                        })
                        ->with(['accommodation', 'images', 'category', 'descriptions'])
                        ->paginate($limit);

        return new RoomTypeResourceCollection($roomTypes);
    }

    public function update($id, UpdateRoomTypeRequest $request): RoomTypeResource
    {
        $roomType = RoomType::find($id);
        $roomType->update($request->all());
        return new RoomTypeResource($roomType->load(['accommodation', 'images', 'category', 'descriptions']));
    }

    public function store(StoreRoomTypeRequest $request): RoomTypeResource
    {
        $roomType = RoomType::create($request->all());
        return new RoomTypeResource($roomType->load(['accommodation', 'images', 'category', 'descriptions']));
    }

    public function destroy(DestroyRoomTypeRequest $request): RoomTypeResource
    {
        $roomType = RoomType::find($request->room_type_id);
        $roomType->delete();
        return new RoomTypeResource($roomType);
    }
}







