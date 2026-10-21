<?php

namespace App\Http\Resources\Admin;

use App\Models\ExperienceCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExperienceCategory */
class ExperienceCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'icon' => $this->icon,
            'sort_order' => $this->sort_order,
            'enabled' => $this->enabled,
        ];
    }
}
