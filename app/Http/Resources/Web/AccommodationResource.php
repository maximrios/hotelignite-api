<?php

namespace App\Http\Resources\Web;

use Illuminate\Http\Resources\Json\JsonResource;

class AccommodationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'slug'           => $this->slug,
            'name'           => $this->name,
            'logo'           => $this->logo,
            'email'          => $this->email,
            'phone'          => $this->phone,
            'address'        => $this->address,
            'city'           => $this->whenLoaded('city', fn () => $this->city?->name),
            'state'          => $this->whenLoaded('state', fn () => $this->state?->name),
            'type'           => $this->whenLoaded('type', fn () => $this->type?->name),
            'latitude'       => $this->latitude,
            'longitude'      => $this->longitude,
            'web'            => $this->web,
            'allow_bookings' => $this->allowsOnlineBookings() ? 1 : 0,
            'images'         => $this->whenLoaded('images', fn () => $this->images),
            'description'    => $this->whenLoaded('descriptions', fn () =>
                $this->descriptions->firstWhere('language_id', 'es')?->description
            ),
            'services'       => $this->whenLoaded('services', fn () => $this->services),
            'policies'       => $this->whenLoaded('policies', fn () =>
                $this->policies->map(fn ($policy) => [
                    'id'          => $policy->id,
                    'name'        => $policy->name,
                    'description' => $policy->pivot->description,
                ])
            ),
        ];
    }
}
