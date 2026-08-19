<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Requests\DestroyRoomRequest;
use App\Http\Requests\StoreRoomRequest;
use App\Http\Requests\UpdateRoomRequest;
use App\Http\Resources\V1\RoomResource;
use App\Http\Resources\V1\RoomResourceCollection;
use App\Models\Accommodation;
use App\Models\Room;
use App\Models\RoomType;
use App\Repositories\Concerns\ScopesToAccommodation;
use App\Repositories\Contracts\RoomInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class RoomRepository implements RoomInterface
{
    use ScopesToAccommodation;

    public function all(Request $request)
    {
        $limit = $request->limit ?: 10;
        $offset = $request->offset ?: 0;

        $rooms = Room::visibleTo($this->tenant())
            ->when($request->accommodation_id, function ($q, $accommodation_id) {
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
        $room = Room::visibleTo($this->tenant())
            ->with(['roomType', 'accommodation'])
            ->find($id);

        abort_if($room === null, 404);

        return new RoomResource($room);
    }

    public function search($request)
    {
        $limit = $request->limit ?: 10;

        $rooms = Room::visibleTo($this->tenant())
            ->when($request->accommodation_id, function ($q, $accommodation_id) {
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
        $accommodation = $this->writableAccommodation($request->accommodation_id);

        $data = $request->validated();
        $data['accommodation_id'] = $accommodation->id;
        $this->assertRoomTypeBelongsTo($data['room_type_id'], $accommodation);

        $room = Room::create($data);

        return new RoomResource($room->load(['roomType', 'accommodation']));
    }

    public function update($id, UpdateRoomRequest $request): RoomResource
    {
        $room = Room::visibleTo($this->tenant())->find($id);

        abort_if($room === null, 404);
        Gate::authorize('update', $room);

        // accommodation_id nunca se reasigna: mudar una habitación a otro
        // alojamiento es cambiarle el dueño, no editarla.
        $data = Arr::except($request->validated(), ['accommodation_id']);

        if (isset($data['room_type_id'])) {
            $this->assertRoomTypeBelongsTo($data['room_type_id'], $room->accommodation);
        }

        $room->update($data);

        return new RoomResource($room->load(['roomType', 'accommodation']));
    }

    public function destroy(DestroyRoomRequest $request): RoomResource
    {
        $room = Room::visibleTo($this->tenant())->find($request->room_id);

        abort_if($room === null, 404);
        Gate::authorize('delete', $room);

        $room->delete();

        return new RoomResource($room);
    }

    /**
     * El tipo de habitación tiene que ser del mismo alojamiento: si no, se podría
     * colgar una habitación propia del catálogo de otra cuenta.
     */
    private function assertRoomTypeBelongsTo($roomTypeId, Accommodation $accommodation): void
    {
        $belongs = RoomType::whereKey($roomTypeId)
            ->where('accommodation_id', $accommodation->id)
            ->exists();

        abort_if(! $belongs, 422, 'El tipo de habitación no pertenece a este alojamiento.');
    }
}
