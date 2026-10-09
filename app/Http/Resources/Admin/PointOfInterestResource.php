<?php

namespace App\Http\Resources\Admin;

use App\Models\PointOfInterest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `distance_m` / `walk_min` sólo vienen en `nearby-points`: el repositorio los
 * agrega como atributos sueltos al modelo.
 *
 * @mixin PointOfInterest
 */
class PointOfInterestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'address' => $this->address,
            'phone' => $this->phone,
            'website' => $this->website,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'is_featured' => $this->is_featured,
            'enabled' => $this->enabled,
            'source' => $this->source,
            'external_id' => $this->external_id,
            'city_id' => $this->city_id,
            'poi_category_id' => $this->poi_category_id,
            'client_id' => $this->client_id,
            // Quién lo cargó: null = staff. Lo muestra el CRM ("Cargado por").
            'client' => $this->whenLoaded('client', fn () => $this->client
                ? ['id' => $this->client->id, 'name' => $this->client->name]
                : null),
            'city' => new CityResource($this->whenLoaded('city')),
            'category' => new PoiCategoryResource($this->whenLoaded('category')),
            'distance_m' => $this->when(
                $this->resource->getAttribute('distance_m') !== null,
                fn () => (int) round((float) $this->resource->getAttribute('distance_m')),
            ),
            'walk_min' => $this->when(
                $this->resource->getAttribute('distance_m') !== null,
                fn () => $this->resource->getAttribute('walk_min'),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
