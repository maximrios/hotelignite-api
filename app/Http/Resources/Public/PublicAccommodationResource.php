<?php

namespace App\Http\Resources\Public;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vista pública de un Accommodation para consumidores B2B (portales, agencias).
 * Deliberadamente NO expone datos internos: `account_id`, `plan_id`, `enabled`,
 * flags de suscripción ni relaciones de facturación. Sólo lo que un portal
 * necesita para mostrar y reservar el alojamiento.
 */
class PublicAccommodationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'logo' => $this->logo,
            'address' => $this->address,
            'state' => $this->state?->name,
            'city' => $this->city?->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'web' => $this->web,
            'type' => $this->type?->name,
            'type_id' => $this->type_id,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'allow_bookings' => $this->allowsOnlineBookings(),
            'description' => $this->publicDescription(),
            'images' => PublicImageResource::collection($this->whenLoaded('images')),
            'services' => PublicServiceResource::collection($this->whenLoaded('services')),
            'rooms' => PublicRoomTypeResource::collection($this->whenLoaded('roomTypes')),
        ];
    }

    /**
     * Descripción en el idioma pedido (?language_id=es por defecto).
     */
    private function publicDescription(): ?string
    {
        $languageId = (string) (request()->get('language_id') ?: 'es');

        return $this->descriptions()->where('language_id', $languageId)->value('description')
            ?? $this->descriptions()->value('description');
    }
}
