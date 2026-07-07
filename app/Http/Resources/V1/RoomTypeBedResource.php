<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class RoomTypeBedResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'           => $this->id,
            'room_type_id' => $this->room_type_id,
            'type'         => $this->type,
            'quantity'     => $this->quantity,
            'room_type'    => $this->whenLoaded('roomType', fn () => [
                'id'           => $this->roomType->id,
                'max_occupancy' => $this->roomType->max_occupancy ?? null,
            ]),
        ];
    }
}
