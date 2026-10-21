<?php

namespace App\Http\Resources\Admin;

use App\Models\City;
use App\Models\Experience;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `duration_kind` (medio / dia / varios) se deriva de la duración; no se guarda.
 * `cities` son los destinos que recorre, sin la ciudad de salida.
 *
 * @mixin Experience
 */
class ExperienceResource extends JsonResource
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
            'meeting_point_name' => $this->meeting_point_name,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'price_type' => $this->price_type,
            'price_from' => $this->price_from,
            'currency' => $this->currency,
            'price_unit' => $this->price_unit,
            'operating_days' => $this->operating_days,
            'season_months' => $this->season_months,
            'operator_name' => $this->operator_name,
            'operator_email' => $this->operator_email,
            'operator_phone' => $this->operator_phone,
            'booking_url' => $this->booking_url,
            'links' => $this->links ?? [],
            'status' => $this->status,
            'is_featured' => $this->is_featured,
            'source' => $this->source,
            'external_id' => $this->external_id,
            'experience_category_id' => $this->experience_category_id,
            'departure_city_id' => $this->departure_city_id,
            'point_of_interest_id' => $this->point_of_interest_id,
            'client_id' => $this->client_id,
            // En qué portal sale: null = del staff, sin asignar.
            'client' => $this->whenLoaded('client', fn () => $this->client
                ? ['id' => $this->client->id, 'name' => $this->client->name]
                : null),
            'category' => new ExperienceCategoryResource($this->whenLoaded('category')),
            'departure_city' => new CityResource($this->whenLoaded('departureCity')),
            'cities' => $this->whenLoaded('cities', fn () => $this->cities
                ->map(fn (City $city) => ['id' => $city->id, 'name' => $city->name, 'slug' => $city->slug])
                ->values()),
            // El punto de encuentro, resumido: lo que hace falta para mostrarlo y ubicarlo.
            'meeting_point' => $this->whenLoaded('meetingPoint', fn () => $this->meetingPoint ? [
                'id' => $this->meetingPoint->id,
                'name' => $this->meetingPoint->name,
                'address' => $this->meetingPoint->address,
                'latitude' => $this->meetingPoint->latitude,
                'longitude' => $this->meetingPoint->longitude,
            ] : null),
            'media' => MediaResource::collection($this->whenLoaded('media')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
