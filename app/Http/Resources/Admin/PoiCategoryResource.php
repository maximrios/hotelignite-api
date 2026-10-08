<?php

namespace App\Http\Resources\Admin;

use App\Models\PoiCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PoiCategory */
class PoiCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'slug' => $this->slug,
            'name' => $this->name,
            'icon' => $this->icon,
            'sort_order' => $this->sort_order,
            'default_radius_m' => $this->default_radius_m,
            'nearest_limit' => $this->nearest_limit,
            'enabled' => $this->enabled,
            'points_count' => $this->whenCounted('pointsOfInterest'),
            'parent' => new PoiCategoryResource($this->whenLoaded('parent')),
            'children' => PoiCategoryResource::collection($this->whenLoaded('children')),
        ];
    }
}
