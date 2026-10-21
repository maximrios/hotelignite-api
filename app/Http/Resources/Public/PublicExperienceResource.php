<?php

namespace App\Http\Resources\Public;

use App\Models\City;
use App\Models\Experience;
use App\Models\Media;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Experiencia para el portal de un client: sin los campos internos (`client_id`,
 * `status`, `source`, `external_id`, timestamps).
 *
 * `meeting_point` es la ubicación efectiva: la propia o, si no tiene, la del
 * lugar de interés enlazado. `media` trae el `embed_url` de YouTube armado sobre
 * `youtube-nocookie.com`, igual que el panel.
 *
 * @mixin Experience
 */
class PublicExperienceResource extends JsonResource
{
    public function toArray($request): array
    {
        $poi = $this->relationLoaded('meetingPoint') ? $this->meetingPoint : null;
        $hasOwnLocation = $this->latitude !== null && $this->longitude !== null;

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'summary' => $this->summary,
            'description' => $this->description,
            'category' => $this->whenLoaded('category', fn () => [
                'slug' => $this->category->slug,
                'name' => $this->category->name,
                'icon' => $this->category->icon,
            ]),
            'duration_value' => $this->duration_value,
            'duration_unit' => $this->duration_unit,
            'duration_kind' => $this->durationKind(),
            'difficulty' => $this->difficulty,
            'min_age' => $this->min_age,
            'group_min' => $this->group_min,
            'group_max' => $this->group_max,
            'languages' => $this->languages ?? [],
            'includes' => $this->includes ?? [],
            'excludes' => $this->excludes ?? [],
            'what_to_bring' => $this->what_to_bring,
            'price' => [
                'type' => $this->price_type,
                'from' => $this->price_from,
                'currency' => $this->currency,
                'unit' => $this->price_unit,
            ],
            'operating_days' => $this->operating_days,
            'season_months' => $this->season_months,
            'meeting_point' => [
                'name' => $this->meeting_point_name ?? $poi?->name,
                'address' => $this->address ?? $poi?->address,
                'latitude' => $hasOwnLocation ? $this->latitude : $poi?->latitude,
                'longitude' => $hasOwnLocation ? $this->longitude : $poi?->longitude,
            ],
            'departure_city' => $this->whenLoaded('departureCity', fn () => $this->departureCity
                ? $this->city($this->departureCity)
                : null),
            'cities' => $this->whenLoaded('cities', fn () => $this->cities->map(fn (City $c) => $this->city($c))->values()),
            'operator' => [
                'name' => $this->operator_name,
                'email' => $this->operator_email,
                'phone' => $this->operator_phone,
            ],
            'booking_url' => $this->booking_url,
            'links' => $this->links ?? [],
            'is_featured' => $this->is_featured,
            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn (Media $m) => [
                'type' => $m->type,
                'url' => $m->url,
                'thumbnail_url' => $m->thumbnail_url,
                'embed_url' => $m->provider === Media::PROVIDER_YOUTUBE && $m->provider_id
                    ? "https://www.youtube-nocookie.com/embed/{$m->provider_id}"
                    : null,
                'alt' => $m->alt,
            ])->values()),
        ];
    }

    /** @return array{id: int, name: string, slug: string|null} */
    private function city(City $city): array
    {
        return ['id' => (int) $city->id, 'name' => $city->name, 'slug' => $city->slug];
    }
}
