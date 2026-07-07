<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

class AccommodationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                  => $this->id,
            'account_id'          => $this->account_id,
            'plan_id'             => $this->plan_id,
            'slug'                => $this->slug,
            'name'                => $this->name,
            'logo'                => $this->logo,
            'email'               => $this->email,
            'phone'               => $this->phone,
            'email_reservations'  => $this->email_reservations,
            'phone_reservations'  => $this->phone_reservations,
            'web'                 => $this->web,
            'address'             => $this->address,
            'postal_code'         => $this->postal_code,
            'city_id'             => $this->city_id,
            'city'                => $this->whenLoaded('city', fn () => $this->city?->name),
            'state_id'            => $this->state_id,
            'state'               => $this->whenLoaded('state', fn () => $this->state?->name),
            'type_id'             => $this->type_id,
            'type'                => $this->whenLoaded('type', fn () => $this->type?->name),
            'latitude'            => $this->latitude,
            'longitude'           => $this->longitude,
            'file_number'         => $this->file_number,
            'tax_identification'  => $this->tax_identification,
            'channel_code'        => $this->channel_code,
            'currency_id'         => $this->currency_id,
            'bank_data'           => $this->bank_data,
            'comment'             => $this->comment,
            'enabled'             => $this->enabled,
            'active'              => $this->active,
            'test'                => $this->test,
            'expiration'          => $this->expiration,
            'images'              => $this->whenLoaded('images', fn () => $this->images),
            'room_types'          => $this->whenLoaded('roomTypes', fn () => $this->roomTypes),
            'services'            => $this->whenLoaded('services', fn () => $this->services),
        ];
    }
}
