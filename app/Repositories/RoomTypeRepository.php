<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Requests\DestroyRoomTypeRequest;
use App\Http\Requests\StoreRoomTypeRequest;
use App\Http\Requests\UpdateRoomTypeRequest;
use App\Http\Resources\V1\RoomTypeResource;
use App\Http\Resources\V1\RoomTypeResourceCollection;
use App\Models\RoomType;
use App\Repositories\Concerns\ScopesToAccommodation;
use App\Repositories\Contracts\RoomTypeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class RoomTypeRepository implements RoomTypeInterface
{
    use ScopesToAccommodation;

    public function all(Request $request)
    {
        $limit = ($request->limit) ? $request->limit : 10;
        $offset = ($request->offset) ? $request->offset : 0;

        $roomTypes = RoomType::visibleTo($this->tenant())
            ->when($request->accommodation_id, function ($q, $accommodation_id) {
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
        $roomType = RoomType::visibleTo($this->tenant())
            ->with(['accommodation', 'images', 'category', 'descriptions'])
            ->find($id);

        abort_if($roomType === null, 404);

        return new RoomTypeResource($roomType);
    }

    public function search($request)
    {
        $limit = ($request->limit) ? $request->limit : 10;

        $roomTypes = RoomType::visibleTo($this->tenant())
            ->when($request->accommodation_id, function ($q, $accommodation_id) {
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
        $roomType = RoomType::visibleTo($this->tenant())->find($id);

        abort_if($roomType === null, 404);
        Gate::authorize('update', $roomType);

        // accommodation_id nunca se reasigna: mover un tipo de habitación a otro
        // alojamiento es cambiarle el dueño, no editarlo.
        $roomType->update(Arr::except($request->validated(), ['accommodation_id']));

        return new RoomTypeResource($roomType->load(['accommodation', 'images', 'category', 'descriptions']));
    }

    public function store(StoreRoomTypeRequest $request): RoomTypeResource
    {
        $accommodation = $this->writableAccommodation($request->accommodation_id);

        $data = $request->validated();
        $data['accommodation_id'] = $accommodation->id;

        $roomType = RoomType::create($data);

        return new RoomTypeResource($roomType->load(['accommodation', 'images', 'category', 'descriptions']));
    }

    public function destroy(DestroyRoomTypeRequest $request): RoomTypeResource
    {
        $roomType = RoomType::visibleTo($this->tenant())->find($request->room_type_id);

        abort_if($roomType === null, 404);
        Gate::authorize('delete', $roomType);

        $roomType->delete();

        return new RoomTypeResource($roomType);
    }
}
