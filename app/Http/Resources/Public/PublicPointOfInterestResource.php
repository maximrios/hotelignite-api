<?php

namespace App\Http\Resources\Public;

use App\Models\PointOfInterest;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Punto de interés para consumidores B2B: sin los campos internos del catálogo
 * (`source`, `external_id`, `enabled`, timestamps). `distance_m` / `walk_min`
 * los agrega `PointOfInterestRepository::nearby` como atributos sueltos.
 *
 * @mixin PointOfInterest
 */
class PublicPointOfInterestResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'address' => $this->address,
            'website' => $this->website,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'is_featured' => (bool) $this->is_featured,
            'category' => $this->whenLoaded('category', fn () => [
                'slug' => $this->category->slug,
                'name' => $this->category->name,
                'icon' => $this->category->icon,
            ]),
            'distance_m' => (int) round((float) $this->resource->getAttribute('distance_m')),
            'walk_min' => $this->resource->getAttribute('walk_min'),
        ];
    }
}
