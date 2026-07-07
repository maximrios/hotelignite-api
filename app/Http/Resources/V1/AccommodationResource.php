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
            'state' => $this->state?->name,
            'city' => $this->city?->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'type' => ($this->type) ? $this->type->name : null,
            'type_id' => $this->type_id,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'allow_bookings' => $this->allowsOnlineBookings() ? 1 : 0,
            'plan_id' => $this->plan_id,
            'plan' => $this->whenLoaded('plan', fn () => [
                'id' => $this->plan->id,
                'slug' => $this->plan->slug,
                'name' => $this->plan->name,
            ]),
            'images' => $this->images,
            'web' => $this->web,
            'enabled' => $this->enabled,
            'services' => $this->services,
            'description' => ($this->descriptions()->count() > 0) ?
                $this->descriptions()->where('language_id', 'es')->first()->description : null,
            'rooms' => new RoomTypeResourceCollection($this->roomTypes),
        ];
    }
}
