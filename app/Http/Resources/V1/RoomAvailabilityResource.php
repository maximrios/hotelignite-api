<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class RoomAvailabilityResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'room_type_id' => $this->room_type_id,
            'date' => $this->date?->toDateString(),
            'available' => $this->available,
            'total' => $this->total,
            'closed' => $this->closed,
            'closed_to_arrival' => $this->closed_to_arrival,
            'closed_to_departure' => $this->closed_to_departure,
            'room_type' => $this->whenLoaded('roomType', function () {
                return [
                    'id' => $this->roomType->id,
                    'max_occupancy' => $this->roomType->max_occupancy,
                ];
            }),
        ];
    }
}
