<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class RoomTypeDescriptionResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'room_type_id' => $this->room_type_id,
            'language_id' => $this->language_id,
            'name' => $this->name,
            'description' => $this->description,
            'room_type' => $this->whenLoaded('roomType', function () {
                return [
                    'id' => $this->roomType->id,
                    'max_occupancy' => $this->roomType->max_occupancy,
                    'status' => $this->roomType->status,
                ];
            }),
        ];
    }
}

