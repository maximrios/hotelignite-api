<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class RatePlanResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'room_type_id' => $this->room_type_id,
            'name' => $this->name,
            'code' => $this->code,
            'cancellation' => $this->cancellation,
            'includes_breakfast' => $this->includes_breakfast,
            'enabled' => $this->enabled,
            'room_type' => $this->whenLoaded('roomType', function () {
                return [
                    'id' => $this->roomType->id,
                    'max_occupancy' => $this->roomType->max_occupancy,
                ];
            }),
            'rates' => $this->whenLoaded('rates', function () {
                return $this->rates->map(fn ($rate) => [
                    'id' => $rate->id,
                    'date' => $rate->date->toDateString(),
                    'price' => $rate->price,
                    'currency' => $rate->currency,
                    'min_stay' => $rate->min_stay,
                    'max_stay' => $rate->max_stay,
                ]);
            }),
        ];
    }
}
