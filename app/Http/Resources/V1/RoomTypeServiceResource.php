<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class RoomTypeServiceResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'room_type_id' => $this->room_type_id,
            'service_id' => $this->service_id,
            'room_type' => $this->whenLoaded('roomType', function () {
                return [
                    'id' => $this->roomType->id,
                    'name' => $this->roomType->name ?? null,
                    'max_occupancy' => $this->roomType->max_occupancy ?? null,
                ];
            }),
            'service' => $this->whenLoaded('service', function () {
                return [
                    'id' => $this->service->id,
                    'name' => $this->service->name ?? null,
                ];
            }),
        ];
    }
}

