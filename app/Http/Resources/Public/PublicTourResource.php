<?php

namespace App\Http\Resources\Public;

use Illuminate\Http\Resources\Json\JsonResource;

class PublicTourResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'web' => $this->web,
            'operator' => $this->channel?->name,
            'images' => PublicImageResource::collection($this->whenLoaded('images')),
        ];
    }
}
