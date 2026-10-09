<?php

namespace App\Http\Resources\Admin;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `next_date`: próxima fecha en que ocurre (hoy, si está en curso; null si
 * terminó). `distance_m` sólo en `nearby-events`.
 *
 * @mixin Event
 */
class EventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'summary' => $this->summary,
            'description' => $this->description,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'start_time' => $this->start_time ? substr((string) $this->start_time, 0, 5) : null,
            'end_time' => $this->end_time ? substr((string) $this->end_time, 0, 5) : null,
            'recurrence' => $this->recurrence,
            'weekdays' => $this->recurrence === 'weekly' ? array_values(array_map('intval', $this->weekdays ?? [])) : null,
            'next_date' => $this->nextOccurrence(Event::today())?->toDateString(),
            'status' => $this->status,
            'venue_name' => $this->venue_name,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'price_type' => $this->price_type,
            'price_from' => $this->price_from,
            'price_to' => $this->price_to,
            'currency' => $this->currency,
            'ticket_url' => $this->ticket_url,
            'organizer_name' => $this->organizer_name,
            'organizer_email' => $this->organizer_email,
            'organizer_phone' => $this->organizer_phone,
            'links' => $this->links ?? [],
            'is_featured' => $this->is_featured,
            'enabled' => $this->enabled,
            'source' => $this->source,
            'external_id' => $this->external_id,
            'city_id' => $this->city_id,
            'event_category_id' => $this->event_category_id,
            'point_of_interest_id' => $this->point_of_interest_id,
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client
                ? ['id' => $this->client->id, 'name' => $this->client->name]
                : null),
            'city' => new CityResource($this->whenLoaded('city')),
            'category' => new EventCategoryResource($this->whenLoaded('category')),
            // La sede, resumida: lo que hace falta para mostrarla y ubicarla.
            'venue' => $this->whenLoaded('venue', fn () => $this->venue ? [
                'id' => $this->venue->id,
                'name' => $this->venue->name,
                'address' => $this->venue->address,
                'latitude' => $this->venue->latitude,
                'longitude' => $this->venue->longitude,
            ] : null),
            'media' => MediaResource::collection($this->whenLoaded('media')),
            // Sólo en `nearby-events`: la primera fecha dentro de la ventana pedida.
            'occurs_on' => $this->when(
                $this->resource->getAttribute('occurs_on') !== null,
                fn () => $this->resource->getAttribute('occurs_on'),
            ),
            'distance_m' => $this->when(
                $this->resource->getAttribute('distance_m') !== null,
                fn () => (int) round((float) $this->resource->getAttribute('distance_m')),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
