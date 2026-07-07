<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'slug'           => $this->slug,
            'icon'           => $this->icon,
            'type'           => $this->type,
            'is_highlighted' => $this->is_highlighted,
            'enabled'        => $this->enabled,
            'qty' => $this->whenLoaded('accommodations', function () {
                return $this->accommodations->count();
            }, function () {
                return $this->accommodations()->count();
            }),
        ];
    }
}
