<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Requests\DestroyRoomAvailabilityRequest;
use App\Http\Requests\StoreRoomAvailabilityRequest;
use App\Http\Requests\UpdateRoomAvailabilityRequest;
use App\Http\Resources\V1\RoomAvailabilityResource;
use App\Http\Resources\V1\RoomAvailabilityResourceCollection;
use App\Models\RoomAvailability;
use App\Repositories\Contracts\RoomAvailabilityInterface;
use Carbon\Carbon;
use Illuminate\Http\Request;

class RoomAvailabilityRepository implements RoomAvailabilityInterface
{
    public function all(Request $request)
    {
        $limit = $request->limit ?: 30;
        $offset = $request->offset ?: 0;

        $availability = RoomAvailability::when($request->room_type_id, function ($q, $room_type_id) {
            return $q->where('room_type_id', $room_type_id);
        })
            ->when($request->date_from, function ($q, $date_from) {
                return $q->where('date', '>=', $date_from);
            })
            ->when($request->date_to, function ($q, $date_to) {
                return $q->where('date', '<=', $date_to);
            })
            ->orderBy('date')
            ->offset($offset)
            ->limit($limit)
            ->get();

        return new RoomAvailabilityResourceCollection($availability);
    }

    public function find($id)
    {
        $availability = RoomAvailability::with('roomType')->find($id);

        return new RoomAvailabilityResource($availability);
    }

    public function search($request)
    {
        $limit = $request->limit ?: 30;

        $availability = RoomAvailability::when($request->room_type_id, function ($q, $room_type_id) {
            return $q->where('room_type_id', $room_type_id);
        })
            ->when($request->date_from, function ($q, $date_from) {
                return $q->where('date', '>=', $date_from);
            })
            ->when($request->date_to, function ($q, $date_to) {
                return $q->where('date', '<=', $date_to);
            })
            ->orderBy('date')
            ->paginate($limit);

        return new RoomAvailabilityResourceCollection($availability);
    }

    public function store(StoreRoomAvailabilityRequest $request): RoomAvailabilityResourceCollection
    {
        // Upsert por rango de fechas: crea o actualiza disponibilidad día a día
        $dateFrom = Carbon::parse($request->date_from);
        $dateTo = Carbon::parse($request->date_to);
        $upserted = [];

        for ($date = $dateFrom->copy(); $date->lte($dateTo); $date->addDay()) {
            $record = RoomAvailability::updateOrCreate(
                ['room_type_id' => $request->room_type_id, 'date' => $date->toDateString()],
                [
                    'available' => $request->available,
                    'total' => $request->total,
                    'closed' => $request->closed ?? false,
                    'closed_to_arrival' => $request->closed_to_arrival ?? false,
                    'closed_to_departure' => $request->closed_to_departure ?? false,
                ]
            );
            $upserted[] = $record;
        }

        return new RoomAvailabilityResourceCollection(collect($upserted));
    }

    public function update($id, UpdateRoomAvailabilityRequest $request): RoomAvailabilityResource
    {
        $availability = RoomAvailability::find($id);
        $availability->update($request->all());

        return new RoomAvailabilityResource($availability);
    }

    public function destroy(DestroyRoomAvailabilityRequest $request): RoomAvailabilityResource
    {
        $availability = RoomAvailability::find($request->room_availability_id);
        $availability->delete();

        return new RoomAvailabilityResource($availability);
    }
}
