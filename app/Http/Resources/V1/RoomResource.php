<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class RoomResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'floor' => $this->floor,
            'status' => $this->status,
            'notes' => $this->notes,
            'room_type_id' => $this->room_type_id,
            'accommodation_id' => $this->accommodation_id,
            'room_type' => $this->whenLoaded('roomType', function () {
                return [
                    'id' => $this->roomType->id,
                    'max_occupancy' => $this->roomType->max_occupancy,
                    'category_id' => $this->roomType->category_id,
                ];
            }),
            'accommodation' => $this->whenLoaded('accommodation', function () {
                return [
                    'id' => $this->accommodation->id,
                    'name' => $this->accommodation->name,
                ];
            }),
        ];
    }
}
