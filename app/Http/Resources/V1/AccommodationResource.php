<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class AccommodationResource extends JsonResource
{
    
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'logo' => $this->logo,
            'name' => $this->name,
            'address' => $this->address,
            'email' => $this->email,
            'phone' => $this->phone,
            'type' => ($this->type) ? $this->type->name:null,
            'type_id' => $this->type_id,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'allow_bookings' => ($this->plan_id === 1) ? 1 : 0,
            'images' => $this->images,
            'services' => $this->services,
            'description' => ($this->descriptions()->count() > 0) ? $this->descriptions()->where('language_id', 'es')->first()->description:null,
            'rooms' => new RoomTypeResourceCollection($this->roomTypes),
        ];
    }
}
